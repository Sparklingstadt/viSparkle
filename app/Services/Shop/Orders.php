<?php

namespace App\Services\Shop;

use App\Billing\BillingException;
use App\Billing\Data\ChargeData;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\PaymentGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderRefunded;
use App\Events\Shop\OrderShipped;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The single writer of an order's state and of the stock it holds (§6.1).
 * Every transition re-reads the order row under a lock and checks its state there, so repeated, concurrent and
 * out-of-order calls change the order (and the stock) at most once. Events are dispatched after the commit.
 */
class Orders
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Take the cart's quantities out of stock and create the pending order with copies of the names and prices.
     * Each decrement is conditional on enough stock of a listed product of this creator, so stock never goes negative.
     *
     * @param  Collection<int, CartItem>  $cartItems
     *
     * @throws ValidationException (key `cart`) when a product is short or no longer listed; nothing is reserved then
     */
    public function reserve(User $buyer, Creator $creator, Collection $cartItems): Order
    {
        if ($cartItems->isEmpty()) {
            throw new LogicException('An order needs at least one cart item.');
        }

        return DB::transaction(function () use ($buyer, $creator, $cartItems) {
            $lines = [];

            // A fixed order of row locks keeps two buyers of the same products from deadlocking.
            foreach ($cartItems->sortBy('product_id') as $cartItem) {
                $quantity = $cartItem->quantity;

                $taken = $quantity < 1 ? 0 : Product::query()
                    ->whereKey($cartItem->product_id)
                    ->where('creator_id', $creator->id)
                    ->listed()
                    ->where('stock', '>=', $quantity)
                    ->decrement('stock', $quantity);

                if ($taken === 0) {
                    throw ValidationException::withMessages(['cart' => '在庫が足りません']);
                }

                $product = Product::query()->findOrFail($cartItem->product_id);
                $lines[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'quantity' => $quantity,
                ];
            }

            $subtotal = array_sum(array_map(fn (array $line) => $line['unit_price'] * $line['quantity'], $lines));

            $order = Order::query()->create([
                'user_id' => $buyer->id,
                'creator_id' => $creator->id,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'shipping_fee' => $creator->shipping_fee,
                'total' => $subtotal + $creator->shipping_fee,
                'fee_percent' => (int) config('fanbox.billing.platform_fee_percent'),
            ]);
            $order->items()->createMany($lines);

            return $order;
        });
    }

    /**
     * pending → paid: save the payment intent, the shipping address and paid_at, take the products out of the buyer's
     * cart and announce it. An order in any other state is returned as it is.
     */
    public function markPaid(Order $order, CheckoutSessionData $session): Order
    {
        return DB::transaction(function () use ($order, $session) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Pending) {
                return $order;
            }

            $order->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
                'stripe_payment_intent_id' => $session->paymentIntentId ?? $order->stripe_payment_intent_id,
                ...$this->shippingColumns($session),
            ])->save();

            if ($order->user_id !== null) {
                CartItem::query()
                    ->where('user_id', $order->user_id)
                    ->whereIn('product_id', $order->items()->whereNotNull('product_id')->pluck('product_id'))
                    ->delete();
            }

            OrderPaid::dispatch($order);

            return $order;
        });
    }

    /**
     * A delayed payment method: the order stays pending (with its stock); only the payment intent and address are saved.
     */
    public function recordAwaitingPayment(Order $order, CheckoutSessionData $session): Order
    {
        return DB::transaction(function () use ($order, $session) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Pending) {
                return $order;
            }

            $order->forceFill([
                'stripe_payment_intent_id' => $session->paymentIntentId ?? $order->stripe_payment_intent_id,
                ...$this->shippingColumns($session),
            ])->save();

            return $order;
        });
    }

    /**
     * pending → canceled, putting the stock back. An order in any other state is returned as it is.
     */
    public function cancel(Order $order, OrderCancelReason $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Pending) {
                return $order;
            }

            $order->forceFill([
                'status' => OrderStatus::Canceled,
                'cancel_reason' => $reason,
                'canceled_at' => now(),
            ])->save();

            $this->restoreStock($order);

            return $order;
        });
    }

    /**
     * paid → shipped.
     *
     * @throws ValidationException (key `order`) when the order is not paid
     */
    public function ship(Order $order, ?string $trackingNumber): Order
    {
        return DB::transaction(function () use ($order, $trackingNumber) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Paid) {
                throw ValidationException::withMessages(['order' => 'この注文は発送できません。']);
            }

            $order->forceFill([
                'status' => OrderStatus::Shipped,
                'shipped_at' => now(),
                'tracking_number' => $trackingNumber,
            ])->save();

            OrderShipped::dispatch($order);

            return $order;
        });
    }

    /**
     * Refund a paid or shipped order in full (the transfer and the platform fee come back too) and mark it refunded.
     * $allowCanceled also refunds a canceled order (a payment that completed after the cancellation).
     * Stock comes back only from paid: shipped goods are gone, and a cancellation already put the stock back.
     * The row lock is held across the gateway call, so a concurrent webhook waits; the idempotency key makes a retry safe.
     *
     * @throws ValidationException (key `order`) when the order cannot be refunded
     * @throws BillingException when the refund fails (nothing is changed)
     */
    public function refund(Order $order, OrderCancelReason $by, bool $allowCanceled = false): Order
    {
        return DB::transaction(function () use ($order, $by, $allowCanceled) {
            $order = $this->lock($order);
            $from = $order->status;

            // A creator may only cancel before shipping; once the goods are out, only an admin (or Stripe) refunds.
            $refundable = $from === OrderStatus::Paid
                || ($from === OrderStatus::Shipped && $by !== OrderCancelReason::Creator)
                || ($allowCanceled && $from === OrderStatus::Canceled);

            if (! $refundable) {
                throw ValidationException::withMessages(['order' => 'この注文は返金できません。']);
            }

            if ($order->stripe_payment_intent_id === null) {
                throw new BillingException("Order {$order->id} has no payment intent to refund.");
            }

            $this->gateway->refundPaymentIntent($order->stripe_payment_intent_id, "order-{$order->id}-refund");

            $this->markRefunded($order, $from, $by);

            return $order;
        });
    }

    /**
     * Mirror a charge's refunds and dispute onto its order. A full refund marks the order refunded (stock as in refund(); a
     * still pending order gets its stock back too).
     */
    public function syncRefund(Order $order, ChargeData $charge): Order
    {
        return DB::transaction(function () use ($order, $charge) {
            $order = $this->lock($order);
            $refunded = min($charge->amountRefunded, $order->total);

            $order->forceFill([
                'refunded_amount' => $refunded,
                'refunded_at' => $refunded > 0 ? ($order->refunded_at ?? now()) : $order->refunded_at,
                'disputed_at' => $charge->disputed ? ($order->disputed_at ?? now()) : $order->disputed_at,
            ])->save();

            $from = $order->status;

            // A pending order can be fully refunded before its delayed payment's success reaches us; it must not turn paid later.
            if ($refunded >= $order->total && in_array($from, [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Canceled], true)) {
                $this->markRefunded($order, $from, $order->cancel_reason);
            }

            return $order;
        });
    }

    /**
     * Inside a transaction: the order's current row, locked.
     */
    private function lock(Order $order): Order
    {
        return Order::query()->lockForUpdate()->findOrFail($order->id);
    }

    /**
     * Inside a transaction, on the locked order: → refunded, putting the stock back when it still held it (paid or pending).
     */
    private function markRefunded(Order $order, OrderStatus $from, ?OrderCancelReason $by): void
    {
        $order->forceFill([
            'status' => OrderStatus::Refunded,
            'refunded_amount' => $order->total,
            'refunded_at' => $order->refunded_at ?? now(),
            'cancel_reason' => $by,
        ])->save();

        if ($from === OrderStatus::Paid || $from === OrderStatus::Pending) {
            $this->restoreStock($order);
        }

        OrderRefunded::dispatch($order);
    }

    /**
     * Inside a transaction, on the locked order: give each item's quantity back (also to a product deleted since).
     */
    private function restoreStock(Order $order): void
    {
        $order->items()
            ->whereNotNull('product_id')
            ->orderBy('product_id')
            ->get()
            ->each(fn (OrderItem $item) => Product::withTrashed()->whereKey($item->product_id)->increment('stock', $item->quantity));
    }

    /**
     * The shipping columns from the session, or none when it has no address.
     *
     * @return array<string, string|null>
     */
    private function shippingColumns(CheckoutSessionData $session): array
    {
        if ($session->shipping === null) {
            return [];
        }

        return [
            'shipping_name' => $session->shipping->name,
            'shipping_postal_code' => $session->shipping->postalCode,
            'shipping_address' => $session->shipping->address,
            'shipping_phone' => $session->shipping->phone,
        ];
    }
}
