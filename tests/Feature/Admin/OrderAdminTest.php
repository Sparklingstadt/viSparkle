<?php

use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Enums\ProductKind;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Shop\Orders;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->admin = User::factory()->admin()->create();
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 500]);
    $this->buyer = User::factory()->create();
});

it('refunds a shipped order as admin and records the action', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = app(Orders::class)->ship(paidOrder($product), null);

    $this->actingAs($this->admin)->post(route('admin.orders.refund', $order))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '注文を返金しました。']);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Refunded)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::Admin)
        ->and($this->gateway->refundedPaymentIntents())->toBe([$order->stripe_payment_intent_id]);
    $action = AdminAction::sole();
    expect($action->action)->toBe('order.refunded')->and($action->note)->toBeNull();
});

it('refunds a paid order as admin and restores stock', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = paidOrder($product);

    $this->actingAs($this->admin)->post(route('admin.orders.refund', $order))->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($product->refresh()->stock)->toBe(5);
});

it('does not refund a pending order', function () {
    $order = Order::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.orders.refund', $order))
        ->assertRedirect()
        ->assertSessionHasErrors(['order' => 'この注文は返金できません。']);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(AdminAction::count())->toBe(0)
        ->and($this->gateway->refundedPaymentIntents())->toBe([]);
});

it('shows a toast and changes nothing when the payment service fails', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    $order = paidOrder($product);
    $this->gateway->refundPaymentIntent((string) $order->stripe_payment_intent_id, 'elsewhere');

    $this->actingAs($this->admin)->post(route('admin.orders.refund', $order))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(AdminAction::count())->toBe(0);
});

it('forbids non-admins', function () {
    $order = Order::factory()->shipped()->create();

    $this->actingAs(User::factory()->create())->post(route('admin.orders.refund', $order))->assertNotFound();

    expect($order->refresh()->status)->toBe(OrderStatus::Shipped);
});

it('lists shop orders on the payments page, newest first, without pending ones', function () {
    $old = Order::factory()->paid()->create(['paid_at' => now()->subDays(2)]);
    $new = Order::factory()->shipped()->create(['paid_at' => now()]);
    Order::factory()->create();

    $this->actingAs($this->admin)->get(route('admin.payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/payments')
            ->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$new->id, $old->id])
            ->where('orders.data.0.status', 'shipped')
            ->where('orders.data.0.status_label', '発送済み')
            ->where('orders.data.0.total', 1500)
            ->where('orders.data.0.refundable', true));
});

it('labels a paid order of digital works only as purchased, as the buyer and the creator see it', function () {
    $order = Order::factory()->paid()->create();
    $order->items()->create(['product_id' => null, 'product_name' => 'PDF', 'product_kind' => ProductKind::Digital, 'unit_price' => 1500, 'quantity' => 1]);

    $this->actingAs($this->admin)->get(route('admin.payments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.data.0.status', 'paid')
            ->where('orders.data.0.status_label', '購入済み')
            ->where('orders.data.0.has_digital', true)
            ->where('orders.data.0.has_physical', false));
});
