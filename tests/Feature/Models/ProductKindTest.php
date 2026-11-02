<?php

use App\Enums\ProductKind;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;

it('defaults products to physical', function () {
    expect(Product::factory()->create()->refresh()->kind)->toBe(ProductKind::Physical);
});

it('makes a digital work purchasable only with a file', function () {
    $work = Product::factory()->digital()->create();
    expect($work->isPurchasable())->toBeFalse();

    ProductFile::factory()->for($work)->create();
    expect($work->refresh()->isPurchasable())->toBeTrue()
        ->and($work->stock)->toBe(0);
});

it('makes a physical product purchasable only in stock', function () {
    expect(Product::factory()->create(['stock' => 1])->isPurchasable())->toBeTrue()
        ->and(Product::factory()->soldOut()->create()->isPurchasable())->toBeFalse();
});

it('tells which kinds an order holds', function () {
    $order = Order::factory()->create();
    $order->items()->create(['product_id' => null, 'product_name' => 'PDF', 'unit_price' => 500, 'quantity' => 1, 'product_kind' => ProductKind::Digital]);

    expect($order->refresh()->hasDigital())->toBeTrue()->and($order->hasPhysical())->toBeFalse();
});

it('keeps an order item kind physical by default', function () {
    $order = Order::factory()->create();
    $item = $order->items()->create(['product_id' => null, 'product_name' => 'マグ', 'unit_price' => 1800, 'quantity' => 1]);

    expect($item->refresh()->product_kind)->toBe(ProductKind::Physical);
});
