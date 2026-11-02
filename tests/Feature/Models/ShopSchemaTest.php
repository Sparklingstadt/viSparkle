<?php

use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps one cart row per user and product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();

    CartItem::create(['user_id' => $user->id, 'product_variant_id' => $product->variants()->value('id'), 'quantity' => 1]);

    expect(fn () => CartItem::create(['user_id' => $user->id, 'product_variant_id' => $product->variants()->value('id'), 'quantity' => 2]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('hides unpublished, hidden and deleted products from listed()', function () {
    $listed = Product::factory()->create();
    Product::factory()->unpublished()->create();
    Product::factory()->create(['hidden_at' => now()]);
    Product::factory()->create()->delete();

    expect(Product::listed()->pluck('id')->all())->toBe([$listed->id])
        ->and($listed->isListed())->toBeTrue();
});

it("keeps orders when the buyer's account is deleted", function () {
    $buyer = User::factory()->create();
    $order = Order::factory()->for($buyer)->create();

    $buyer->delete();

    expect($order->fresh())->not->toBeNull()
        ->and($order->fresh()->user_id)->toBeNull();
});

it('tells whether a creator can sell', function () {
    expect(Creator::factory()->create()->canSell())->toBeFalse()
        ->and(Creator::factory()->payable()->create()->canSell())->toBeTrue()
        ->and(Creator::factory()->payable()->closed()->create()->canSell())->toBeFalse()
        ->and(Creator::factory()->payable()->for(User::factory()->suspended())->create()->canSell())->toBeFalse();
});
