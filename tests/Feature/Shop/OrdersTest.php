<?php

use App\Billing\BillingException;
use App\Billing\Data\ChargeData;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Events\Shop\OrderRefunded;
use App\Events\Shop\OrderShipped;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Shop\Orders;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 500]);
    $this->buyer = User::factory()->create();
});

/**
 * Put the products in the buyer's cart (product => quantity) and return the cart rows.
 *
 * @param  array<int, array{0: Product, 1: int}>  $lines
 */
function shopCart(User $buyer, array $lines): Collection
{
    return collect($lines)->map(fn (array $line) => CartItem::query()->create([
        'user_id' => $buyer->id,
        'product_variant_id' => $line[0]->variants()->value('id'),
        'quantity' => $line[1],
    ]));
}

/**
 * Reserve the product for the buyer, check it out and pay on the fake gateway, and mark the order paid.
 */
function shopPaidOrder(Product $product, int $quantity = 1): Order
{
    $orders = app(Orders::class);
    $order = $orders->reserve(test()->buyer, test()->creator, shopCart(test()->buyer, [[$product, $quantity]]));
    $sessionId = fakeOrderCheckout(test()->gateway, $order);
    test()->gateway->completePaymentCheckout($sessionId);

    return $orders->markPaid($order, test()->gateway->retrieveCheckoutSession($sessionId));
}

it('reserves stock and copies names and prices', function () {
    $mug = Product::factory()->for($this->creator)->create(['name' => 'マグカップ', 'price' => 1800, 'stock' => 5]);
    $sticker = Product::factory()->for($this->creator)->create(['name' => 'ステッカー', 'price' => 300, 'stock' => 10]);

    $order = app(Orders::class)->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$mug, 2], [$sticker, 3]]));

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($this->buyer->id)
        ->and($order->creator_id)->toBe($this->creator->id)
        ->and($order->subtotal)->toBe(1800 * 2 + 300 * 3)
        ->and($order->shipping_fee)->toBe(500)
        ->and($order->total)->toBe(1800 * 2 + 300 * 3 + 500)
        ->and($order->fee_percent)->toBe((int) config('fanbox.billing.platform_fee_percent'))
        ->and($mug->refresh()->stock)->toBe(3)
        ->and($sticker->refresh()->stock)->toBe(7);

    $items = $order->items()->orderBy('product_id')->get();
    expect($items)->toHaveCount(2)
        ->and($items[0]->only(['product_id', 'product_name', 'unit_price', 'quantity']))
        ->toBe(['product_id' => $mug->id, 'product_name' => 'マグカップ', 'unit_price' => 1800, 'quantity' => 2])
        ->and($items[1]->only(['product_id', 'product_name', 'unit_price', 'quantity']))
        ->toBe(['product_id' => $sticker->id, 'product_name' => 'ステッカー', 'unit_price' => 300, 'quantity' => 3]);
});

it('refuses the second buyer of the last item', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 1]);
    $other = User::factory()->create();
    $orders = app(Orders::class);

    $orders->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$product, 1]]));

    try {
        $orders->reserve($other, $this->creator, shopCart($other, [[$product, 1]]));
        $this->fail('The second reservation should fail.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['cart' => ['在庫が足りません']]);
    }

    expect($product->refresh()->stock)->toBe(0)
        ->and(Order::query()->count())->toBe(1);
});

it('reserves nothing when one of the products is short', function () {
    $plenty = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $short = Product::factory()->for($this->creator)->create(['stock' => 1]);

    expect(fn () => app(Orders::class)->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$plenty, 2], [$short, 2]])))
        ->toThrow(ValidationException::class);

    expect($plenty->refresh()->stock)->toBe(5)
        ->and($short->refresh()->stock)->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});

it('refuses unlisted products and products of another creator', function (Closure $make) {
    $product = $make($this->creator);

    expect(fn () => app(Orders::class)->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$product, 1]])))
        ->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
})->with([
    'unpublished' => fn (Creator $creator) => Product::factory()->for($creator)->unpublished()->create(),
    'hidden' => fn (Creator $creator) => Product::factory()->for($creator)->create(['hidden_at' => now()]),
    'deleted' => fn (Creator $creator) => tap(Product::factory()->for($creator)->create())->delete(),
    'another creator' => fn (Creator $creator) => Product::factory()->create(),
]);

it('cancels a pending order once and restores stock once', function () {
    $this->travelTo(now()->startOfSecond());
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $orders = app(Orders::class);
    $order = $orders->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$product, 2]]));

    $orders->cancel($order, OrderCancelReason::Abandoned);
    $again = $orders->cancel($order, OrderCancelReason::Expired);

    expect($again->status)->toBe(OrderStatus::Canceled)
        ->and($again->cancel_reason)->toBe(OrderCancelReason::Abandoned)
        ->and($again->canceled_at->equalTo(now()))->toBeTrue()
        ->and($product->refresh()->stock)->toBe(5);
});

it('does not cancel an order that is no longer pending', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = shopPaidOrder($product);

    $result = app(Orders::class)->cancel($order, OrderCancelReason::Expired);

    expect($result->status)->toBe(OrderStatus::Paid)
        ->and($result->cancel_reason)->toBeNull()
        ->and($product->refresh()->stock)->toBe(4);
});

it('ships a paid order', function () {
    Event::fake([OrderShipped::class]);
    $order = shopPaidOrder(Product::factory()->for($this->creator)->create());

    $shipped = app(Orders::class)->ship($order, 'TRK-123');

    expect($shipped->status)->toBe(OrderStatus::Shipped)
        ->and($shipped->tracking_number)->toBe('TRK-123')
        ->and($shipped->shipped_at)->not->toBeNull();
    Event::assertDispatchedTimes(OrderShipped::class, 1);
});

it('does not ship an unpaid order', function () {
    Event::fake([OrderShipped::class]);
    $product = Product::factory()->for($this->creator)->create();
    $order = app(Orders::class)->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$product, 1]]));

    try {
        app(Orders::class)->ship($order, null);
        $this->fail('An unpaid order should not ship.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['order']);
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
    Event::assertNotDispatched(OrderShipped::class);
});

it('refunds a paid order and restores stock', function () {
    Event::fake([OrderRefunded::class]);
    $this->travelTo(now()->startOfSecond());
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = shopPaidOrder($product, 2);
    expect($product->refresh()->stock)->toBe(3);

    $refunded = app(Orders::class)->refund($order, OrderCancelReason::Creator);

    expect($refunded->status)->toBe(OrderStatus::Refunded)
        ->and($refunded->refunded_amount)->toBe($order->total)
        ->and($refunded->refunded_at->equalTo(now()))->toBeTrue()
        ->and($refunded->cancel_reason)->toBe(OrderCancelReason::Creator)
        ->and($this->gateway->refundedPaymentIntents())->toBe([$order->stripe_payment_intent_id])
        ->and($this->gateway->refundIdempotencyKeys())->toBe([(string) $order->stripe_payment_intent_id => "order-{$order->id}-refund"])
        ->and($product->refresh()->stock)->toBe(5);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('refunds a shipped order without restoring stock', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = app(Orders::class)->ship(shopPaidOrder($product), null);

    $refunded = app(Orders::class)->refund($order, OrderCancelReason::Admin);

    expect($refunded->status)->toBe(OrderStatus::Refunded)
        ->and($refunded->cancel_reason)->toBe(OrderCancelReason::Admin)
        ->and($product->refresh()->stock)->toBe(4);
});

it('refuses a creator refund of a shipped order', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = app(Orders::class)->ship(shopPaidOrder($product), null);

    expect(fn () => app(Orders::class)->refund($order, OrderCancelReason::Creator))->toThrow(ValidationException::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and($this->gateway->refundedPaymentIntents())->toBe([]);
});

it('refuses to refund a pending, canceled or refunded order', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $orders = app(Orders::class);
    $pending = $orders->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$product, 1]]));
    $canceled = Order::factory()->for($this->creator)->create(['status' => OrderStatus::Canceled, 'cancel_reason' => OrderCancelReason::Expired]);
    $refunded = $orders->refund(shopPaidOrder(Product::factory()->for($this->creator)->create()), OrderCancelReason::Admin);

    foreach ([$pending, $canceled, $refunded] as $order) {
        expect(fn () => $orders->refund($order, OrderCancelReason::Admin))->toThrow(ValidationException::class);
    }

    expect($pending->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($canceled->refresh()->status)->toBe(OrderStatus::Canceled)
        ->and($this->gateway->refundedPaymentIntents())->toHaveCount(1);
});

it('changes nothing when the refund call fails', function () {
    Event::fake([OrderRefunded::class]);
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = shopPaidOrder($product);
    $this->gateway->refundPaymentIntent((string) $order->stripe_payment_intent_id, 'elsewhere');

    expect(fn () => app(Orders::class)->refund($order, OrderCancelReason::Creator))->toThrow(BillingException::class);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->refunded_amount)->toBe(0)
        ->and($order->refunded_at)->toBeNull()
        ->and($order->cancel_reason)->toBeNull()
        ->and($product->refresh()->stock)->toBe(4);
    Event::assertNotDispatched(OrderRefunded::class);
});

it('mirrors a full refund at Stripe once and records a dispute', function () {
    Event::fake([OrderRefunded::class]);
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = shopPaidOrder($product);
    $chargeId = $this->gateway->chargeIdForPaymentIntent((string) $order->stripe_payment_intent_id);
    $this->gateway->refundPaymentIntent((string) $order->stripe_payment_intent_id, 'dashboard');
    $this->gateway->markDisputed($chargeId);
    $orders = app(Orders::class);

    $orders->syncRefund($order, $this->gateway->retrieveCharge($chargeId));
    $synced = $orders->syncRefund($order, $this->gateway->retrieveCharge($chargeId));

    expect($synced->status)->toBe(OrderStatus::Refunded)
        ->and($synced->refunded_amount)->toBe($order->total)
        ->and($synced->refunded_at)->not->toBeNull()
        ->and($synced->disputed_at)->not->toBeNull()
        ->and($product->refresh()->stock)->toBe(5);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('records a partial refund at Stripe without changing the status', function () {
    $order = shopPaidOrder(Product::factory()->for($this->creator)->create());
    $charge = new ChargeData('ch_partial', null, 700, false, $order->stripe_payment_intent_id);

    $synced = app(Orders::class)->syncRefund($order, $charge);

    expect($synced->status)->toBe(OrderStatus::Paid)
        ->and($synced->refunded_amount)->toBe(700)
        ->and($synced->disputed_at)->toBeNull();
});

it('moves a pending order that is fully refunded to refunded and restores its stock once', function () {
    Event::fake([OrderRefunded::class]);
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = app(Orders::class)->reserve($this->buyer, $this->creator, shopCart($this->buyer, [[$product, 2]]));
    $sessionId = fakeOrderCheckout($this->gateway, $order);
    $this->gateway->completePaymentCheckout($sessionId, paid: false);
    $session = $this->gateway->retrieveCheckoutSession($sessionId);
    app(Orders::class)->recordAwaitingPayment($order, $session);
    $this->gateway->settleAsyncPayment($sessionId, true);
    $this->gateway->refundPaymentIntent((string) $session->paymentIntentId, 'dashboard');
    $charge = $this->gateway->retrieveCharge($this->gateway->chargeIdForPaymentIntent((string) $session->paymentIntentId));

    app(Orders::class)->syncRefund($order, $charge);
    app(Orders::class)->syncRefund($order, $charge);

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->paid_at)->toBeNull()
        ->and($product->refresh()->stock)->toBe(5);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});
