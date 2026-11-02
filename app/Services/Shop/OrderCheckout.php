<?php

namespace App\Services\Shop;

use App\Billing\BillingException;
use App\Billing\Data\LineItem;
use App\Billing\Data\PaymentCheckoutRequest;
use App\Billing\PaymentGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Models\Creator;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use Illuminate\Validation\ValidationException;

/**
 * Turns a creator's part of the cart into a pending order and a Stripe Checkout session (§6.2 ①),
 * and handles the buyer coming back from it. The order's state itself is only ever written by Orders.
 */
class OrderCheckout
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly CheckoutService $customers,
        private readonly Cart $cart,
        private readonly Orders $orders,
        private readonly OrderSync $sync,
    ) {}

    /**
     * Reserve the stock, create the Checkout session and return its URL.
     *
     * @throws ValidationException (key `cart`)
     */
    public function begin(User $buyer, Creator $creator): string
    {
        if ($buyer->isSuspended() || ! $creator->canSell()) {
            throw self::unavailable();
        }

        $this->replaceUnfinished($buyer, $creator);

        // After the replacement: the stock the buyer's own unfinished order held is back by now.
        $group = $this->cart->group($buyer, $creator);

        if ($group === null || ! $group['purchasable']) {
            throw self::unavailable();
        }

        $order = $this->orders->reserve($buyer, $creator, $group['items']);

        try {
            $session = $this->gateway->createPaymentCheckoutSession(new PaymentCheckoutRequest(
                customerId: $this->customers->customerId($buyer),
                lineItems: $this->lineItems($order),
                destinationAccountId: (string) $creator->stripe_account_id,
                applicationFeeAmount: intdiv($order->total * $order->fee_percent, 100),
                metadata: [
                    'app' => (string) config('fanbox.billing.idempotency_prefix'),
                    'user_id' => (string) $buyer->id,
                    'creator_id' => (string) $creator->id,
                    'order_id' => (string) $order->id,
                ],
                successUrl: route('orders.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: route('cart.index', ['canceled' => $order->id]),
                // Stripe requires at least 30 minutes by its own clock, so give it a minute of slack.
                expiresAt: now()->addMinutes((int) config('fanbox.billing.checkout_expires_minutes') + 1),
                idempotencyKey: "order-{$order->id}-checkout",
            ));
        } catch (BillingException $e) {
            report($e);
            $this->orders->cancel($order, OrderCancelReason::CheckoutError);

            throw ValidationException::withMessages(['cart' => '決済の準備に失敗しました']);
        }

        $order->forceFill(['stripe_checkout_session_id' => $session->id])->save();

        return $session->url;
    }

    /**
     * The buyer left Stripe's page without paying: close the session and give the stock back.
     * Only their own pending order without a payment is touched.
     */
    public function abandon(User $buyer, int $orderId): void
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->where('user_id', $buyer->id)
            ->where('status', OrderStatus::Pending)
            ->whereNull('stripe_payment_intent_id')
            ->first();

        if ($order !== null) {
            $this->close($order);
        }
    }

    /**
     * The buyer came back from Checkout: bring the order in line with the session without waiting for the webhook.
     * 404 when the session is not theirs or not an order's.
     *
     * @throws BillingException
     */
    public function complete(User $buyer, string $sessionId): Order
    {
        $session = $this->gateway->retrieveCheckoutSession($sessionId);

        abort_unless(($session->metadata['user_id'] ?? null) === (string) $buyer->id, 404);

        $order = $this->sync->syncSession($sessionId);
        abort_if($order === null, 404);

        return $order;
    }

    /**
     * One open checkout per buyer and creator, so a second tab cannot reserve the stock twice.
     *
     * @throws ValidationException (key `cart`) when a delayed payment is still being settled
     */
    private function replaceUnfinished(User $buyer, Creator $creator): void
    {
        $pending = Order::query()
            ->where('user_id', $buyer->id)
            ->where('creator_id', $creator->id)
            ->where('status', OrderStatus::Pending)
            ->get();

        // A payment already made (waiting to settle) cannot be thrown away; check them all before closing any.
        if ($pending->contains(fn (Order $order) => $order->stripe_payment_intent_id !== null)) {
            throw ValidationException::withMessages(['cart' => 'お支払い待ちの注文があります']);
        }

        $pending->each(fn (Order $order) => $this->close($order));
    }

    private function close(Order $order): void
    {
        if ($order->stripe_checkout_session_id !== null) {
            try {
                $this->gateway->expireCheckoutSession($order->stripe_checkout_session_id);
            } catch (BillingException) {
                // Already gone or unreachable; the order is what matters.
            }
        }

        $this->orders->cancel($order, OrderCancelReason::Abandoned);
    }

    /**
     * @return list<LineItem>
     */
    private function lineItems(Order $order): array
    {
        $lines = array_values($order->items
            ->map(fn (OrderItem $item) => new LineItem($item->product_name, $item->unit_price, $item->quantity))
            ->all());

        if ($order->shipping_fee > 0) {
            $lines[] = new LineItem('送料', $order->shipping_fee, 1);
        }

        return $lines;
    }

    private static function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['cart' => '現在購入できません']);
    }
}
