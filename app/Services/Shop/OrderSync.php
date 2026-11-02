<?php

namespace App\Services\Shop;

use App\Billing\BillingException;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\PaymentGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Brings an order in line with its Stripe checkout session (§6.2 ②), from webhooks, the return URL and the hourly check.
 * Always re-reads the session and goes through Orders, so duplicate and out-of-order calls converge on the same result.
 */
class OrderSync
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Orders $orders,
    ) {}

    /**
     * Returns null when the session is not one of this app's orders.
     *
     * @throws BillingException
     */
    public function syncSession(string $sessionId): ?Order
    {
        $session = $this->gateway->retrieveCheckoutSession($sessionId);
        $order = $this->orderOf($session);

        if ($order === null) {
            return null;
        }

        return match (true) {
            $session->status === 'complete' && $session->paymentStatus === 'paid' => $this->paid($order, $session),
            $session->status === 'complete' && $session->paymentStatus === 'unpaid' => $this->orders->recordAwaitingPayment($order, $session),
            $session->status === 'expired' => $this->orders->cancel($order, OrderCancelReason::Expired),
            default => $order,
        };
    }

    /**
     * A delayed payment failed: cancel the order. Returns null when the session is not one of this app's orders.
     *
     * @throws BillingException
     */
    public function syncAsyncFailure(string $sessionId): ?Order
    {
        $session = $this->gateway->retrieveCheckoutSession($sessionId);
        $order = $this->orderOf($session);

        if ($order === null) {
            return null;
        }

        // The session is the truth: if it says paid after all, never cancel a paid-for order.
        if ($session->paymentStatus === 'paid') {
            return $this->paid($order, $session);
        }

        return $this->orders->cancel($order, OrderCancelReason::PaymentFailed);
    }

    /**
     * The payment completed. Normally pending → paid; if the order was canceled meanwhile (its stock may be sold again),
     * the payment is refunded in full instead.
     */
    private function paid(Order $order, CheckoutSessionData $session): Order
    {
        $order = $this->orders->markPaid($order, $session);

        if ($order->status !== OrderStatus::Canceled) {
            return $order;
        }

        $order = $this->rememberPaymentIntent($order, $session);

        if ($order->status !== OrderStatus::Canceled) {
            return $order;
        }

        Log::error('shop.paid_after_cancel', [
            'order_id' => $order->id,
            'session' => $session->id,
            'payment_intent' => $order->stripe_payment_intent_id,
        ]);

        try {
            return $this->orders->refund($order, $order->cancel_reason ?? OrderCancelReason::Expired, allowCanceled: true);
        } catch (ValidationException) {
            // A concurrent sync refunded it first.
            return $order->refresh();
        }
    }

    /**
     * Save the session's payment intent on a canceled order (it never got one), so it can be refunded.
     */
    private function rememberPaymentIntent(Order $order, CheckoutSessionData $session): Order
    {
        return DB::transaction(function () use ($order, $session) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status === OrderStatus::Canceled && $order->stripe_payment_intent_id === null) {
                $order->forceFill(['stripe_payment_intent_id' => $session->paymentIntentId])->save();
            }

            return $order;
        });
    }

    /**
     * The order the session belongs to, or null when it is another app's, has no order, or is not that order's session.
     */
    private function orderOf(CheckoutSessionData $session): ?Order
    {
        if (($session->metadata['app'] ?? null) !== config('fanbox.billing.idempotency_prefix')) {
            return null;
        }

        $orderId = $session->metadata['order_id'] ?? null;

        if (! is_numeric($orderId)) {
            return null;
        }

        $order = Order::query()->find((int) $orderId);

        if ($order === null) {
            Log::warning('shop.session_unattributed', ['session' => $session->id, 'order_id' => $orderId]);

            return null;
        }

        if ($order->stripe_checkout_session_id !== null && $order->stripe_checkout_session_id !== $session->id) {
            Log::warning('shop.session_mismatch', ['session' => $session->id, 'order_id' => $order->id]);

            return null;
        }

        return $order;
    }
}
