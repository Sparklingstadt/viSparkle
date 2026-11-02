<?php

use App\Models\Creator;
use App\Models\Order;

it('charges the platform rate on goods and shipping and the support rate on support', function () {
    $order = Order::factory()->make([
        'subtotal' => 1801, 'shipping_fee' => 0, 'support_amount' => 333,
        'total' => 2134, 'fee_percent' => 10, 'support_fee_percent' => 5,
    ]);

    expect($order->platformFeeAmount())->toBe(180 + 16);
});

it('matches the old fee when there is no support', function () {
    $order = Order::factory()->make(['subtotal' => 3600, 'shipping_fee' => 600, 'total' => 4200, 'fee_percent' => 10]);

    expect($order->platformFeeAmount())->toBe(intdiv(4200 * 10, 100));
});

it('defaults the new columns', function () {
    expect(Order::factory()->create()->fresh())
        ->support_amount->toBe(0)
        ->support_fee_percent->toBe(0)
        ->and(Creator::factory()->create()->fresh()->accepts_support)->toBeTrue();
});

it('builds an order with support', function () {
    $order = Order::factory()->withSupport(500)->create();

    expect($order)
        ->support_amount->toBe(500)
        ->support_fee_percent->toBe(5)
        ->total->toBe(1500 + 500);
});
