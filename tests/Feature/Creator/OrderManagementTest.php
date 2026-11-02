<?php

use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Services\Shop\Orders;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 500]);
    $this->buyer = User::factory()->create();
    $this->actingAs($this->creator->user);
});

/** Reserve, pay on the fake gateway and mark the order paid, as the buyer's checkout would. */
function paidShopOrder(Product $product, int $quantity = 1, int $supportAmount = 0): Order
{
    $orders = app(Orders::class);
    $cart = collect([CartItem::query()->create([
        'user_id' => test()->buyer->id,
        'product_variant_id' => $product->variants()->value('id'),
        'quantity' => $quantity,
    ])]);
    $order = $orders->reserve(test()->buyer, test()->creator, $cart, $supportAmount);
    $sessionId = fakeOrderCheckout(test()->gateway, $order);
    test()->gateway->completePaymentCheckout($sessionId);

    return $orders->markPaid($order, test()->gateway->retrieveCheckoutSession($sessionId));
}

it('ships a paid order with a tracking number', function () {
    $order = Order::factory()->paid()->for($this->creator)->create();

    $this->post(route('creator.orders.ship', $order), ['tracking_number' => 'JP123'])
        ->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(OrderStatus::Shipped)->tracking_number->toBe('JP123')->shipped_at->not->toBeNull();
});

it('ships without a tracking number but rejects an overlong one', function () {
    $order = Order::factory()->paid()->for($this->creator)->create();

    $this->post(route('creator.orders.ship', $order), ['tracking_number' => str_repeat('a', 101)])
        ->assertSessionHasErrors('tracking_number');
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    $this->post(route('creator.orders.ship', $order))->assertSessionHasNoErrors();
    expect($order->fresh())->status->toBe(OrderStatus::Shipped)->tracking_number->toBeNull();
});

it('cannot ship an order that is not paid', function () {
    $order = Order::factory()->for($this->creator)->create();

    $this->post(route('creator.orders.ship', $order))->assertSessionHasErrors('order');
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('refunds a paid order and restores stock', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = paidShopOrder($product, 2);
    expect($product->refresh()->stock)->toBe(3);

    $this->post(route('creator.orders.refund', $order))->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(OrderStatus::Refunded)->refunded_amount->toBe($order->total)
        ->and($product->refresh()->stock)->toBe(5)
        ->and($this->gateway->refundedPaymentIntents())->toBe([$order->stripe_payment_intent_id]);
});

it('refunds the support along with the goods when the creator cancels', function () {
    $product = Product::factory()->for($this->creator)->create(['price' => 1500, 'stock' => 5]);
    $order = paidShopOrder($product, 1, 800);
    expect($order->total)->toBe(1500 + 500 + 800);

    $this->post(route('creator.orders.refund', $order))->assertSessionHasNoErrors();

    expect($order->fresh())->status->toBe(OrderStatus::Refunded)->refunded_amount->toBe(1500 + 500 + 800);
});

it('cannot refund a shipped order', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = app(Orders::class)->ship(paidShopOrder($product), null);

    $this->post(route('creator.orders.refund', $order))->assertSessionHasErrors('order');

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and($this->gateway->refundedPaymentIntents())->toBe([]);
});

it('keeps the order and shows a toast when the refund call fails', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = paidShopOrder($product);
    $this->gateway->refundPaymentIntent((string) $order->stripe_payment_intent_id, 'elsewhere');
    // The gateway already refunded it, so our own refund call now fails.
    $this->from(route('creator.orders.show', $order))
        ->post(route('creator.orders.refund', $order))
        ->assertRedirect(route('creator.orders.show', $order))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it("hides other creators' orders", function () {
    $order = Order::factory()->paid()->create();

    $this->get(route('creator.orders.show', $order))->assertNotFound();
    $this->post(route('creator.orders.ship', $order))->assertNotFound();
    $this->post(route('creator.orders.refund', $order))->assertNotFound();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('shows an order to its creator', function () {
    $product = Product::factory()->for($this->creator)->create(['name' => 'ステッカー']);
    $order = Order::factory()->paid()->for($this->buyer)->for($this->creator)->withItem($product, 3)->create([
        'shipping_name' => '山田 太郎',
        'shipping_phone' => '09012345678',
    ]);

    $this->get(route('creator.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/orders/show')
            ->where('order.id', $order->id)
            ->where('order.status', 'paid')
            ->where('order.shipping_name', '山田 太郎')
            ->where('order.shipping_phone', '09012345678')
            ->where('order.items.0.product_name', 'ステッカー')
            ->where('order.can_refund', true)
            ->where('order.can_ship', true));
});

it('filters orders by status', function () {
    $paid = Order::factory()->paid()->for($this->creator)->create();
    $shipped = Order::factory()->shipped()->for($this->creator)->create();
    $refunded = Order::factory()->paid()->for($this->creator)->create(['status' => OrderStatus::Refunded]);
    $pending = Order::factory()->for($this->creator)->create();
    Order::factory()->paid()->create();

    $ids = fn (string $query = '') => collect(
        $this->get(route('creator.orders.index').$query)->assertOk()->viewData('page')['props']['orders']['data'],
    )->pluck('id')->all();

    expect($ids())->toEqualCanonicalizing([$paid->id, $shipped->id, $refunded->id])
        ->and($ids('?status=paid'))->toBe([$paid->id])
        ->and($ids('?status=pending'))->toBe([$pending->id])
        ->and($ids('?status=bogus'))->toEqualCanonicalizing([$paid->id, $shipped->id, $refunded->id]);
});

it('paginates 20 per page, newest first', function () {
    Order::factory()->count(21)->paid()->for($this->creator)->sequence(fn ($s) => ['created_at' => now()->subMinutes(30 - $s->index)])->create();

    $this->get(route('creator.orders.index'))->assertInertia(fn (Assert $page) => $page
        ->has('orders.data', 20)
        ->where('orders.last_page', 2));
});

it('requires a creator', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('creator.orders.index'))->assertRedirect();
});

/**
 * A published digital work of the creator with one file.
 */
function managedWork(): Product
{
    $work = Product::factory()->digital()->for(test()->creator)->create(['name' => 'イラスト集']);
    ProductFile::factory()->for($work)->create();

    return $work;
}

it('hides ship and refund for a digital-only order', function () {
    $order = Order::factory()->paid()->for($this->creator)->withItem(managedWork())->create();

    $this->get(route('creator.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.can_ship', false)
            ->where('order.can_refund', false)
            ->where('order.status_label', '購入済み')
            ->where('order.has_physical', false)
            ->where('order.items.0.product_kind', 'digital'));

    $this->post(route('creator.orders.refund', $order))->assertSessionHasErrors(['order' => 'デジタル作品を含む注文は取り消せません。']);
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('offers only shipping for a mixed order', function () {
    $order = Order::factory()->paid()->for($this->creator)
        ->withItem(managedWork())
        ->withItem(Product::factory()->for($this->creator)->create())
        ->create();

    $this->get(route('creator.orders.show', $order))
        ->assertInertia(fn (Assert $page) => $page->where('order.can_ship', true)->where('order.can_refund', false));
});
