<?php

use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Events\Shop\OrderPaid;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Shop\Orders;
use App\Services\Shop\OrderSync;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 500]);
    $this->buyer = User::factory()->create();
    $this->product = Product::factory()->for($this->creator)->create(['price' => 1200, 'stock' => 5]);
});

/**
 * Put the product in the buyer's cart, reserve it and open its checkout session; returns [order, session ID].
 *
 * @return array{0: Order, 1: string}
 */
function syncReservedOrder(int $quantity = 2): array
{
    $cart = collect([CartItem::query()->create(['user_id' => test()->buyer->id, 'product_id' => test()->product->id, 'quantity' => $quantity])]);
    $order = app(Orders::class)->reserve(test()->buyer, test()->creator, $cart);

    return [$order, fakeOrderCheckout(test()->gateway, $order)];
}

it("marks a paid session's order as paid with shipping details", function () {
    Event::fake([OrderPaid::class]);
    $this->travelTo(now()->startOfSecond());
    $otherCreatorsItem = CartItem::query()->create(['user_id' => $this->buyer->id, 'product_id' => Product::factory()->create()->id, 'quantity' => 1]);
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->completePaymentCheckout($sessionId);
    $paymentIntentId = $this->gateway->retrieveCheckoutSession($sessionId)->paymentIntentId;

    $synced = app(OrderSync::class)->syncSession($sessionId);

    expect($synced?->id)->toBe($order->id);
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at->equalTo(now()))->toBeTrue()
        ->and($order->stripe_payment_intent_id)->toBe($paymentIntentId)
        ->and($order->shipping_name)->toBe('テスト 太郎')
        ->and($order->shipping_postal_code)->toBe('100-0001')
        ->and($order->shipping_address)->toBe('東京都 千代田区 千代田1-1')
        ->and($order->shipping_phone)->toBe('09000000000')
        ->and($this->product->refresh()->stock)->toBe(3)
        ->and(CartItem::query()->pluck('id')->all())->toBe([$otherCreatorsItem->id]);
    Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event) => $event->order->is($order));
});

it('keeps a delayed payment pending with its payment intent', function () {
    Event::fake([OrderPaid::class]);
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->completePaymentCheckout($sessionId, paid: false);

    app(OrderSync::class)->syncSession($sessionId);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->stripe_payment_intent_id)->toBe($this->gateway->retrieveCheckoutSession($sessionId)->paymentIntentId)
        ->and($order->shipping_name)->toBe('テスト 太郎')
        ->and($order->paid_at)->toBeNull()
        ->and($this->product->refresh()->stock)->toBe(3)
        ->and(CartItem::query()->count())->toBe(1);
    Event::assertNotDispatched(OrderPaid::class);

    $this->gateway->settleAsyncPayment($sessionId, true);
    app(OrderSync::class)->syncSession($sessionId);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(CartItem::query()->count())->toBe(0);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('cancels the order of a failed delayed payment', function () {
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->completePaymentCheckout($sessionId, paid: false);
    app(OrderSync::class)->syncSession($sessionId);
    $this->gateway->settleAsyncPayment($sessionId, false);

    app(OrderSync::class)->syncAsyncFailure($sessionId);
    app(OrderSync::class)->syncAsyncFailure($sessionId);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Canceled)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::PaymentFailed)
        ->and($this->product->refresh()->stock)->toBe(5);
});

it("cancels an expired session's order", function () {
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->expireCheckoutSession($sessionId);

    app(OrderSync::class)->syncSession($sessionId);
    app(OrderSync::class)->syncSession($sessionId);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Canceled)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::Expired)
        ->and($this->product->refresh()->stock)->toBe(5);
});

it('leaves the order of an open session alone', function () {
    [$order, $sessionId] = syncReservedOrder();

    $synced = app(OrderSync::class)->syncSession($sessionId);

    expect($synced?->status)->toBe(OrderStatus::Pending)
        ->and($this->product->refresh()->stock)->toBe(3);
});

it('syncs the same session twice without double effects', function () {
    Event::fake([OrderPaid::class]);
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->completePaymentCheckout($sessionId);
    $sync = app(OrderSync::class);

    $sync->syncSession($sessionId);
    $paidAt = $order->refresh()->paid_at;
    $this->travel(5)->minutes();
    CartItem::query()->create(['user_id' => $this->buyer->id, 'product_id' => $this->product->id, 'quantity' => 1]);
    $sync->syncSession($sessionId);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at->equalTo($paidAt))->toBeTrue()
        ->and($this->product->refresh()->stock)->toBe(3)
        ->and(CartItem::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1);
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});

it('refunds a payment that arrives after cancellation', function () {
    Event::fake([OrderPaid::class]);
    Log::spy();
    [$order, $sessionId] = syncReservedOrder();
    app(Orders::class)->cancel($order, OrderCancelReason::Abandoned);
    expect($this->product->refresh()->stock)->toBe(5);
    $this->gateway->completePaymentCheckout($sessionId);
    $paymentIntentId = $this->gateway->retrieveCheckoutSession($sessionId)->paymentIntentId;

    app(OrderSync::class)->syncSession($sessionId);
    app(OrderSync::class)->syncSession($sessionId);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::Abandoned)
        ->and($order->stripe_payment_intent_id)->toBe($paymentIntentId)
        ->and($order->refunded_amount)->toBe($order->total)
        ->and($this->gateway->refundedPaymentIntents())->toBe([$paymentIntentId])
        ->and($this->product->refresh()->stock)->toBe(5);
    Log::shouldHaveReceived('error')->with('shop.paid_after_cancel', Mockery::on(fn (array $context) => $context['order_id'] === $order->id))->once();
    Event::assertNotDispatched(OrderPaid::class);
});

it('mirrors a refund made before the order was synced as paid', function () {
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->completePaymentCheckout($sessionId);
    $paymentIntentId = (string) $this->gateway->retrieveCheckoutSession($sessionId)->paymentIntentId;
    $this->gateway->refundPaymentIntent($paymentIntentId, 'dashboard-refund');
    expect($this->product->refresh()->stock)->toBe(3);

    app(OrderSync::class)->syncSession($sessionId);
    app(OrderSync::class)->syncSession($sessionId);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refunded_amount)->toBe($order->total)
        ->and($this->product->refresh()->stock)->toBe(5);
});

it('leaves a paid order paid when its charge has no refunds', function () {
    [$order, $sessionId] = syncReservedOrder();
    $this->gateway->completePaymentCheckout($sessionId);

    app(OrderSync::class)->syncSession($sessionId);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->refunded_amount)->toBe(0);
});

it('ignores sessions from another app or without an order', function (Closure $metadata) {
    [$order, $sessionId] = syncReservedOrder();
    $foreignOrder = Order::factory()->create(['user_id' => $this->buyer->id]);
    $foreignSessionId = fakeOrderCheckout($this->gateway, $foreignOrder, $metadata($order));
    $this->gateway->completePaymentCheckout($foreignSessionId);

    expect(app(OrderSync::class)->syncSession($foreignSessionId))->toBeNull();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($foreignOrder->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'another app' => fn (Order $order) => ['app' => 'staging/other.example', 'order_id' => (string) $order->id],
    'no app' => fn (Order $order) => ['order_id' => (string) $order->id],
    'no order' => fn (Order $order) => ['app' => (string) config('fanbox.billing.idempotency_prefix'), 'plan_id' => '1'],
    'unknown order' => fn (Order $order) => ['app' => (string) config('fanbox.billing.idempotency_prefix'), 'order_id' => '999999'],
]);

it("ignores a session that is not the order's own", function () {
    [$order] = syncReservedOrder();
    $strayOrder = Order::factory()->create(['user_id' => $this->buyer->id]);
    $straySessionId = fakeOrderCheckout($this->gateway, $strayOrder, [
        'app' => (string) config('fanbox.billing.idempotency_prefix'),
        'order_id' => (string) $order->id,
    ]);
    $this->gateway->completePaymentCheckout($straySessionId);

    expect(app(OrderSync::class)->syncSession($straySessionId))->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);
});
