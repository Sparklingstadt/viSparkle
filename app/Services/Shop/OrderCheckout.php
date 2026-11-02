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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
     * @throws AwaitingPaymentException when an order of this creator is awaiting a delayed payment
     */
    public function begin(User $buyer, Creator $creator, int $supportAmount = 0): string
    {
        if ($buyer->isSuspended() || ! $creator->canSell()) {
            throw self::unavailable();
        }

        // One begin at a time per buyer and creator, so two tabs cannot both pass the replacement and reserve twice.
        try {
            return Cache::lock("order-checkout:{$buyer->id}:{$creator->id}", 60)
                ->block(5, fn () => $this->beginLocked($buyer, $creator, $supportAmount));
        } catch (LockTimeoutException) {
            throw self::unavailable();
        }
    }

    private function beginLocked(User $buyer, Creator $creator, int $supportAmount): string
    {
        $this->replaceUnfinished($buyer, $creator);

        // After the replacement: the stock the buyer's own unfinished order held is back by now.
        $group = $this->cart->group($buyer, $creator);

        if ($group === null) {
            throw self::unavailable();
        }

        if (! $group['purchasable']) {
            throw ValidationException::withMessages(['cart' => $this->cart->refusal($buyer, $group)]);
        }

        $order = $this->orders->reserve($buyer, $creator, $group['items'], $supportAmount);

        try {
            $session = $this->gateway->createPaymentCheckoutSession(new PaymentCheckoutRequest(
                customerId: $this->customers->customerId($buyer),
                lineItems: $this->lineItems($order),
                destinationAccountId: (string) $creator->stripe_account_id,
                applicationFeeAmount: $order->platformFeeAmount(),
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
                collectShipping: $order->hasPhysical(),
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
     * @throws AwaitingPaymentException when a delayed payment is still being settled
     */
    private function replaceUnfinished(User $buyer, Creator $creator): void
    {
        $pending = Order::query()
            ->where('user_id', $buyer->id)
            ->where('creator_id', $creator->id)
            ->where('status', OrderStatus::Pending)
            ->get();

        // A payment already made (waiting to settle) cannot be thrown away; check them all before closing any.
        $awaiting = $pending->first(fn (Order $order) => $order->stripe_payment_intent_id !== null);

        if ($awaiting !== null) {
            throw new AwaitingPaymentException($awaiting);
        }

        // Closing re-reads each order from Stripe: one that was paid or is awaiting payment is kept as it is.
        $closed = $pending->map(fn (Order $order) => $this->close($order));
        $awaiting = $closed->first(fn (Order $order) => $order->status === OrderStatus::Pending && $order->stripe_payment_intent_id !== null);

        if ($awaiting !== null) {
            throw new AwaitingPaymentException($awaiting);
        }
    }

    /**
     * Cancel the order as abandoned, unless its Checkout turns out to be finished (paid, or a payment awaiting settlement):
     * Stripe's side is synced first, and again if expiring the session failed. Returns the order as it stands.
     */
    private function close(Order $order): Order
    {
        $sessionId = $order->stripe_checkout_session_id;

        if ($sessionId !== null) {
            $order = $this->resync($order, $sessionId);

            if (! $this->isOpen($order)) {
                return $order;
            }

            try {
                $this->gateway->expireCheckoutSession($sessionId);
            } catch (BillingException) {
                // Perhaps it completed meanwhile: look again before giving the order up.
                $order = $this->resync($order, $sessionId);

                if (! $this->isOpen($order)) {
                    return $order;
                }
            }
        }

        return $this->orders->cancel($order, OrderCancelReason::Abandoned);
    }

    private function resync(Order $order, string $sessionId): Order
    {
        try {
            $this->sync->syncSession($sessionId);
        } catch (BillingException $e) {
            report($e);
        }

        return $order->fresh() ?? $order;
    }

    /**
     * Still pending with no payment started: the only kind of order that may be thrown away.
     */
    private function isOpen(Order $order): bool
    {
        return $order->status === OrderStatus::Pending && $order->stripe_payment_intent_id === null;
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

        if ($order->support_amount > 0) {
            $lines[] = new LineItem('クリエイターへの応援', $order->support_amount, 1);
        }

        return $lines;
    }

    private static function unavailable(): ValidationException
    {
        return ValidationException::withMessages(['cart' => '現在購入できません']);
    }
}
