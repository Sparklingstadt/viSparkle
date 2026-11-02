<?php

use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use App\Services\Shop\Cart;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 600]);
    $this->buyer = User::factory()->create();
});

it('adds up quantities up to the stock', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 3])->assertSessionHasNoErrors();

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::first()->quantity)->toBe(5);
});

it('refuses more than the stock', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    app(Cart::class)->add($this->buyer, $product, 4);

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])
        ->assertSessionHasErrors(['cart' => '在庫が足りません']);

    expect(CartItem::first()->quantity)->toBe(4);
});

it('refuses own products', function () {
    $product = Product::factory()->for($this->creator)->create();

    $this->actingAs($this->creator->user)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
        ->assertSessionHasErrors(['cart' => '自分の商品は購入できません。']);

    expect(CartItem::count())->toBe(0);
});

it('refuses products that are not listed or whose creator cannot show a shop', function () {
    $cart = app(Cart::class);
    $unpublished = Product::factory()->for($this->creator)->unpublished()->create();
    $closed = Product::factory()->for(Creator::factory()->payable()->closed())->create();
    $deleted = Product::factory()->for($this->creator)->create();
    $deleted->delete();

    foreach ([$unpublished, $closed] as $product) {
        expect(fn () => $cart->add($this->buyer, $product, 1))->toThrow(ValidationException::class, '現在購入できません');
    }

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_id' => $deleted->id, 'quantity' => 1])
        ->assertSessionHasErrors(['cart' => '現在購入できません']);
});

it('validates the quantity', function () {
    $product = Product::factory()->for($this->creator)->create();

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 0])
        ->assertSessionHasErrors('quantity');
});

it('requires login', function () {
    $this->post(route('cart.items.store'), ['product_id' => 1, 'quantity' => 1])->assertRedirect(route('login'));
    $this->get(route('cart.index'))->assertRedirect(route('login'));
});

it('changes and removes a cart row, only the owners', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    app(Cart::class)->add($this->buyer, $product, 1);
    $row = CartItem::first();
    $other = User::factory()->create();

    $this->actingAs($other)->put(route('cart.items.update', $row), ['quantity' => 2])->assertNotFound();
    $this->actingAs($other)->delete(route('cart.items.destroy', $row))->assertNotFound();

    $this->actingAs($this->buyer)->put(route('cart.items.update', $row), ['quantity' => 4])->assertSessionHasNoErrors();
    expect($row->fresh()->quantity)->toBe(4);

    $this->actingAs($this->buyer)->put(route('cart.items.update', $row), ['quantity' => 6])
        ->assertSessionHasErrors(['cart' => '在庫が足りません']);
    expect($row->fresh()->quantity)->toBe(4);

    $this->actingAs($this->buyer)->delete(route('cart.items.destroy', $row))->assertSessionHasNoErrors();
    expect(CartItem::count())->toBe(0);
});

it('groups items by creator with shipping fee', function () {
    $free = Creator::factory()->payable()->create(['shipping_fee' => 0]);
    $mug = Product::factory()->for($this->creator)->create(['price' => 1800]);
    $sticker = Product::factory()->for($this->creator)->create(['price' => 300]);
    $pin = Product::factory()->for($free)->create(['price' => 500]);
    $cart = app(Cart::class);
    $cart->add($this->buyer, $mug, 2);
    $cart->add($this->buyer, $pin, 1);
    $cart->add($this->buyer, $sticker, 3);

    $groups = $cart->groups($this->buyer);

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['creator']->is($this->creator))->toBeTrue()
        ->and($groups[0]['items'])->toHaveCount(2)
        ->and($groups[0]['subtotal'])->toBe(1800 * 2 + 300 * 3)
        ->and($groups[0]['shipping_fee'])->toBe(600)
        ->and($groups[0]['total'])->toBe(4500 + 600)
        ->and($groups[0]['purchasable'])->toBeTrue()
        ->and($groups[1]['creator']->is($free))->toBeTrue()
        ->and($groups[1]['shipping_fee'])->toBe(0)
        ->and($groups[1]['total'])->toBe(500);

    $this->actingAs($this->buyer)->get(route('cart.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fan/cart')
            ->has('groups', 2)
            ->where('groups.0.total', 5100)
            ->has('groups.0.items', 2));
});

it('marks a group with a sold-out item as not purchasable', function () {
    $mug = Product::factory()->for($this->creator)->create(['stock' => 3]);
    $sticker = Product::factory()->for($this->creator)->create(['stock' => 3]);
    $cart = app(Cart::class);
    $cart->add($this->buyer, $mug, 1);
    $cart->add($this->buyer, $sticker, 2);
    $sticker->forceFill(['stock' => 1])->save();

    expect($cart->groups($this->buyer)[0]['purchasable'])->toBeFalse();

    $sticker->forceFill(['stock' => 2])->save();
    expect($cart->groups($this->buyer)[0]['purchasable'])->toBeTrue();

    $sticker->forceFill(['is_published' => false])->save();
    expect($cart->groups($this->buyer)[0]['purchasable'])->toBeFalse();
});

it('marks a group of a creator without payouts as not purchasable', function () {
    $creator = Creator::factory()->create();
    $product = Product::factory()->for($creator)->create();
    app(Cart::class)->add($this->buyer, $product, 1);

    expect(app(Cart::class)->groups($this->buyer)[0]['purchasable'])->toBeFalse();
});

it('keeps a deleted product in the cart as not purchasable', function () {
    $product = Product::factory()->for($this->creator)->create();
    app(Cart::class)->add($this->buyer, $product, 1);
    $product->delete();

    $groups = app(Cart::class)->groups($this->buyer);

    expect($groups)->toHaveCount(1)->and($groups[0]['purchasable'])->toBeFalse();
    $this->actingAs($this->buyer)->get(route('cart.index'))->assertOk();
});

it('shows only the buyers own cart', function () {
    $product = Product::factory()->for($this->creator)->create();
    app(Cart::class)->add(User::factory()->create(), $product, 1);

    $this->actingAs($this->buyer)->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->has('groups', 0));
});
