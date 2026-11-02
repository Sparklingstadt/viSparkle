<?php

namespace App\Services\Shop;

use App\Billing\BillingException;
use App\Billing\Data\ChargeData;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\PaymentGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Enums\ProductKind;
use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderRefunded;
use App\Events\Shop\OrderShipped;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
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
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly DigitalAccess $access,
    ) {}

    /**
     * Take the cart's quantities out of stock and create the pending order with copies of the names, kinds and prices,
     * plus the buyer's support for the creator (yen, 0 for none) at the support rate of now.
     * Each decrement is conditional on enough stock of a variant of a listed product of this creator, so stock never goes negative.
     * A digital work takes no stock: it must be a listed work of this creator with a file, one of it, not bought by the
     * buyer already. Shipping is charged only when the order holds goods.
     *
     * @param  Collection<int, CartItem>  $cartItems
     *
     * @throws ValidationException (key `cart`) when a product is short or no longer listed; nothing is reserved then
     */
    public function reserve(User $buyer, Creator $creator, Collection $cartItems, int $supportAmount = 0): Order
    {
        if ($cartItems->isEmpty()) {
            throw new LogicException('An order needs at least one cart item.');
        }

        return DB::transaction(function () use ($buyer, $creator, $cartItems, $supportAmount) {
            $lines = [];

            // A fixed order of row locks keeps two buyers of the same products from deadlocking.
            foreach ($cartItems->sortBy('product_variant_id') as $cartItem) {
                $quantity = $cartItem->quantity;
                $variant = ProductVariant::query()->with('product')->find($cartItem->product_variant_id);

                if ($variant?->product->isDigital()) {
                    $this->checkWork($buyer, $creator, $variant, $quantity);
                } else {
                    $taken = $quantity < 1 ? 0 : ProductVariant::query()
                        ->whereKey($cartItem->product_variant_id)
                        ->whereHas('product', fn ($product) => $product->withoutTrashed()->where('creator_id', $creator->id)->listed())
                        ->where('stock', '>=', $quantity)
                        ->decrement('stock', $quantity);

                    if ($taken === 0) {
                        throw ValidationException::withMessages(['cart' => '在庫が足りません']);
                    }
                }

                $variant ??= ProductVariant::query()->with('product')->findOrFail($cartItem->product_variant_id);
                $lines[] = [
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'product_name' => $variant->label(),
                    'product_kind' => $variant->product->kind,
                    'unit_price' => $variant->product->price,
                    'quantity' => $quantity,
                ];
            }

            $subtotal = array_sum(array_map(fn (array $line) => $line['unit_price'] * $line['quantity'], $lines));
            $hasGoods = collect($lines)->contains(fn (array $line) => $line['product_kind'] === ProductKind::Physical);
            $shippingFee = $hasGoods ? $creator->shipping_fee : 0;

            $order = Order::query()->create([
                'user_id' => $buyer->id,
                'creator_id' => $creator->id,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'support_amount' => $supportAmount,
                'total' => $subtotal + $shippingFee + $supportAmount,
                'fee_percent' => (int) config('fanbox.billing.platform_fee_percent'),
                'support_fee_percent' => (int) config('fanbox.billing.support_fee_percent'),
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
                    ->whereIn('product_variant_id', $order->items()->whereNotNull('product_variant_id')->pluck('product_variant_id'))
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
     * paid → shipped. An order of digital works only has nothing to ship; paid is its last state.
     *
     * @throws ValidationException (key `order`) when the order is not paid or holds no goods
     */
    public function ship(Order $order, ?string $trackingNumber): Order
    {
        return DB::transaction(function () use ($order, $trackingNumber) {
            $order = $this->lock($order);

            if ($order->status !== OrderStatus::Paid || ($order->hasDigital() && ! $order->hasPhysical())) {
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

            // A downloaded file cannot be given back: only an admin refunds an order with a digital work (§6.4).
            if ($by === OrderCancelReason::Creator && $order->hasDigital()) {
                throw ValidationException::withMessages(['order' => 'デジタル作品を含む注文は取り消せません。']);
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
     * Inside a transaction, on the locked order: give each item of goods its quantity back (also to a variant or product
     * deleted since). Digital works hold no stock.
     */
    private function restoreStock(Order $order): void
    {
        $order->items()
            ->whereNotNull('product_variant_id')
            ->where('product_kind', ProductKind::Physical)
            ->orderBy('product_variant_id')
            ->get()
            ->each(fn (OrderItem $item) => ProductVariant::withTrashed()->whereKey($item->product_variant_id)->increment('stock', $item->quantity));
    }

    /**
     * Inside the reservation: the work is a listed one of this creator, with a file, one of it, and not bought already.
     * The product row is locked so a concurrent unpublish or file removal waits for the order.
     *
     * @throws ValidationException (key `cart`)
     */
    private function checkWork(User $buyer, Creator $creator, ProductVariant $variant, int $quantity): void
    {
        $work = Product::query()
            ->whereKey($variant->product_id)
            ->where('creator_id', $creator->id)
            ->listed()
            ->lockForUpdate()
            ->first();

        if ($work !== null && $this->access->hasPurchased($buyer, $work)) {
            throw ValidationException::withMessages(['cart' => '購入済みの作品です']);
        }

        if ($work === null || $quantity !== 1 || ! $work->files()->exists()) {
            throw ValidationException::withMessages(['cart' => '現在購入できません']);
        }
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
