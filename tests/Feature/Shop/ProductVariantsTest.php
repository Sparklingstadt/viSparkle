<?php

use App\Enums\OrderCancelReason;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use App\Services\Shop\Cart;
use App\Services\Shop\Orders;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->creator = Creator::factory()->payable()->create();
    $this->buyer = User::factory()->create();
});

it('creates a product with several kinds in the order given', function () {
    $this->actingAs($this->creator->user)->post(route('creator.products.store'), [
        'name' => 'Tシャツ', 'price' => 3500,
        'variants' => [['name' => 'S', 'stock' => 2], ['name' => 'M', 'stock' => 5], ['name' => 'フリー', 'stock' => 0]],
    ])->assertSessionHasNoErrors();

    $product = $this->creator->products()->sole();

    expect($product->variants->pluck('name')->all())->toBe(['S', 'M', 'フリー'])
        ->and($product->variants->pluck('stock')->all())->toBe([2, 5, 0])
        ->and($product->stock)->toBe(7);
});

it('needs a distinct name for each kind when there are several', function (array $variants, string $field) {
    $this->actingAs($this->creator->user)
        ->post(route('creator.products.store'), ['name' => 'Tシャツ', 'price' => 3500, 'variants' => $variants])
        ->assertSessionHasErrors($field);

    expect($this->creator->products()->count())->toBe(0);
})->with([
    'unnamed' => [[['name' => 'S', 'stock' => 1], ['name' => '', 'stock' => 1]], 'variants.1.name'],
    'same name' => [[['name' => 'S', 'stock' => 1], ['name' => 'S', 'stock' => 1]], 'variants.1.name'],
    'none' => [[], 'variants'],
]);

it('updates kinds: changes stock by the difference, adds new ones and removes left-out ones', function () {
    $product = Product::factory()->for($this->creator)->withVariants(['S' => 3, 'M' => 3, 'L' => 3])->create();
    [$s, $m, $l] = $product->variants->all();
    $m->decrement('stock'); // sold while the form was open

    $this->actingAs($this->creator->user)->put(route('creator.products.update', $product), [
        'name' => 'Tシャツ', 'price' => 3500,
        'variants' => [
            ['id' => $m->id, 'name' => 'M', 'stock' => 5, 'stock_seen' => 3],
            ['id' => $s->id, 'name' => 'S', 'stock' => 3, 'stock_seen' => 3],
            ['name' => 'XL', 'stock' => 4],
        ],
    ])->assertSessionHasNoErrors();

    $variants = $product->fresh()->variants;

    expect($variants->pluck('name')->all())->toBe(['M', 'S', 'XL'])
        ->and($variants->pluck('stock')->all())->toBe([4, 3, 4])
        ->and($l->fresh()->trashed())->toBeTrue();
});

it("refuses another product's kind in the form", function () {
    $product = Product::factory()->for($this->creator)->create();
    $other = Product::factory()->for($this->creator)->create();

    $this->actingAs($this->creator->user)->put(route('creator.products.update', $product), [
        'name' => 'x', 'price' => 500,
        'variants' => [['id' => $other->variants()->value('id'), 'name' => null, 'stock' => 1, 'stock_seen' => 5]],
    ])->assertSessionHasErrors('variants');

    expect($other->fresh()->stock)->toBe(5);
});

it('keeps a row per kind in the cart, each held to its own stock', function () {
    $product = Product::factory()->for($this->creator)->withVariants(['S' => 1, 'M' => 5])->create(['name' => 'Tシャツ']);
    [$s, $m] = $product->variants->all();
    $cart = app(Cart::class);

    $cart->add($this->buyer, $s, 1);
    $cart->add($this->buyer, $m, 2);

    expect(fn () => $cart->add($this->buyer, $s, 1))->toThrow(ValidationException::class, '在庫が足りません')
        ->and(CartItem::query()->where('user_id', $this->buyer->id)->count())->toBe(2);

    $this->actingAs($this->buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.items.0.name', 'Tシャツ（S）')
            ->where('groups.0.items.1.name', 'Tシャツ（M）')
            ->where('groups.0.items.1.stock', 5));
});

it('refuses a kind that was removed, and shows a cart row of it as not purchasable', function () {
    $product = Product::factory()->for($this->creator)->withVariants(['S' => 3, 'M' => 3])->create();
    [$s, $m] = $product->variants->all();
    app(Cart::class)->add($this->buyer, $s, 1);
    $s->delete();

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $s->id, 'quantity' => 1])
        ->assertSessionHasErrors(['cart' => '現在購入できません']);

    expect(app(Cart::class)->groups($this->buyer)[0]['purchasable'])->toBeFalse();
});

it('reserves only the chosen kind with its name, and gives it back on cancel', function () {
    $product = Product::factory()->for($this->creator)->withVariants(['S' => 3, 'M' => 3])->create(['name' => 'Tシャツ', 'price' => 3500]);
    [$s, $m] = $product->variants->all();
    app(Cart::class)->add($this->buyer, $m, 2);
    $orders = app(Orders::class);

    $order = $orders->reserve($this->buyer, $this->creator->refresh(), CartItem::query()->where('user_id', $this->buyer->id)->get());

    expect($order->items()->sole()->only(['product_id', 'product_variant_id', 'product_name', 'unit_price', 'quantity']))
        ->toBe(['product_id' => $product->id, 'product_variant_id' => $m->id, 'product_name' => 'Tシャツ（M）', 'unit_price' => 3500, 'quantity' => 2])
        ->and($m->fresh()->stock)->toBe(1)
        ->and($s->fresh()->stock)->toBe(3);

    $orders->cancel($order, OrderCancelReason::Abandoned);

    expect($m->fresh()->stock)->toBe(3);
});

it('shows the kinds on the product page', function () {
    $product = Product::factory()->for($this->creator)->withVariants(['S' => 0, 'M' => 2])->create();

    $this->get(route('shop.products.show', [$this->creator, $product->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('product.variants', 2)
            ->where('product.variants.0.name', 'S')
            ->where('product.variants.0.stock', 0)
            ->where('product.variants.1.stock', 2)
            ->where('product.stock', 2));
});
