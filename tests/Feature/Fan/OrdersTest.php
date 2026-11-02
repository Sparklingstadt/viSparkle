<?php

use App\Enums\OrderStatus;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->buyer = User::factory()->create();
    $this->creator = Creator::factory()->payable()->create(['shop_contact_email' => 'shop@example.com']);
    $this->product = Product::factory()->for($this->creator)->create(['name' => 'マグカップ', 'price' => 1800]);
});

it('lists own orders newest first', function () {
    $old = Order::factory()->paid()->for($this->buyer)->for($this->creator)->create(['created_at' => now()->subDays(2)]);
    $new = Order::factory()->shipped()->for($this->buyer)->for($this->creator)->withItem($this->product)->create(['created_at' => now()->subDay()]);
    Order::factory()->paid()->create();

    $this->actingAs($this->buyer)->get(route('orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fan/orders/index')
            ->has('orders.data', 2)
            ->where('orders.data.0.id', $new->id)
            ->where('orders.data.0.status', 'shipped')
            ->where('orders.data.0.status_label', '発送済み')
            ->where('orders.data.0.items.0.product_name', 'マグカップ')
            ->where('orders.data.1.id', $old->id));
});

it("hides other users' orders", function () {
    $order = Order::factory()->paid()->create();

    $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertNotFound();
});

it('requires login', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
});

it("shows the creator's contact and tracking number", function () {
    $order = Order::factory()->shipped()->for($this->buyer)->for($this->creator)->withItem($this->product, 2)->create([
        'subtotal' => 3600,
        'shipping_fee' => 500,
        'total' => 4100,
        'shipping_name' => '山田 太郎',
        'shipping_postal_code' => '1500001',
        'shipping_address' => '東京都渋谷区1-2-3',
        'tracking_number' => 'JP123456789',
    ]);

    $this->actingAs($this->buyer)->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fan/orders/show')
            ->where('order.id', $order->id)
            ->where('order.status_label', '発送済み')
            ->where('order.tracking_number', 'JP123456789')
            ->where('order.subtotal', 3600)
            ->where('order.shipping_fee', 500)
            ->where('order.total', 4100)
            ->where('order.shipping_name', '山田 太郎')
            ->where('order.items.0.quantity', 2)
            ->where('order.contact_email', 'shop@example.com')
            ->where('order.creator.slug', $this->creator->slug));
});

it('shows a refund made without a reason', function () {
    $order = Order::factory()->paid()->for($this->buyer)->for($this->creator)->create([
        'status' => OrderStatus::Refunded,
        'refunded_amount' => 1500,
        'refunded_at' => now(),
        'cancel_reason' => null,
    ]);

    $this->actingAs($this->buyer)->get(route('orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.status_label', '返金済み')
            ->where('order.refunded_amount', 1500));
});
