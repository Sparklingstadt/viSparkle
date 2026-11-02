<?php

use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
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

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $product->variants()->value('id'), 'quantity' => 2])->assertSessionHasNoErrors();
    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $product->variants()->value('id'), 'quantity' => 3])->assertSessionHasNoErrors();

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::first()->quantity)->toBe(5);
});

it('refuses more than the stock', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    app(Cart::class)->add($this->buyer, $product->variants()->firstOrFail(), 4);

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $product->variants()->value('id'), 'quantity' => 2])
        ->assertSessionHasErrors(['cart' => '在庫が足りません']);

    expect(CartItem::first()->quantity)->toBe(4);
});

it('refuses own products', function () {
    $product = Product::factory()->for($this->creator)->create();

    $this->actingAs($this->creator->user)->post(route('cart.items.store'), ['product_variant_id' => $product->variants()->value('id'), 'quantity' => 1])
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
        expect(fn () => $cart->add($this->buyer, $product->variants()->firstOrFail(), 1))->toThrow(ValidationException::class, '現在購入できません');
    }

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $deleted->variants()->value('id'), 'quantity' => 1])
        ->assertSessionHasErrors(['cart' => '現在購入できません']);
});

it('validates the quantity', function () {
    $product = Product::factory()->for($this->creator)->create();

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $product->variants()->value('id'), 'quantity' => 0])
        ->assertSessionHasErrors('quantity');
});

it('requires login', function () {
    $this->post(route('cart.items.store'), ['product_variant_id' => 1, 'quantity' => 1])->assertRedirect(route('login'));
    $this->get(route('cart.index'))->assertRedirect(route('login'));
});

it('changes and removes a cart row, only the owners', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);
    app(Cart::class)->add($this->buyer, $product->variants()->firstOrFail(), 1);
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
    $cart->add($this->buyer, $mug->variants()->firstOrFail(), 2);
    $cart->add($this->buyer, $pin->variants()->firstOrFail(), 1);
    $cart->add($this->buyer, $sticker->variants()->firstOrFail(), 3);

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
    $cart->add($this->buyer, $mug->variants()->firstOrFail(), 1);
    $cart->add($this->buyer, $sticker->variants()->firstOrFail(), 2);
    $sticker->variants()->update(['stock' => 1]);

    expect($cart->groups($this->buyer)[0]['purchasable'])->toBeFalse();

    $sticker->variants()->update(['stock' => 2]);
    expect($cart->groups($this->buyer)[0]['purchasable'])->toBeTrue();

    $sticker->forceFill(['is_published' => false])->save();
    expect($cart->groups($this->buyer)[0]['purchasable'])->toBeFalse();
});

it('marks a group of a creator without payouts as not purchasable', function () {
    $creator = Creator::factory()->create();
    $product = Product::factory()->for($creator)->create();
    app(Cart::class)->add($this->buyer, $product->variants()->firstOrFail(), 1);

    expect(app(Cart::class)->groups($this->buyer)[0]['purchasable'])->toBeFalse();
});

it('keeps a deleted product in the cart as not purchasable', function () {
    $product = Product::factory()->for($this->creator)->create();
    app(Cart::class)->add($this->buyer, $product->variants()->firstOrFail(), 1);
    $product->delete();

    $groups = app(Cart::class)->groups($this->buyer);

    expect($groups)->toHaveCount(1)->and($groups[0]['purchasable'])->toBeFalse();
    $this->actingAs($this->buyer)->get(route('cart.index'))->assertOk();
});

it('shows only the buyers own cart', function () {
    $product = Product::factory()->for($this->creator)->create();
    app(Cart::class)->add(User::factory()->create(), $product->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->get(route('cart.index'))->assertInertia(fn (Assert $page) => $page->has('groups', 0));
});

/**
 * A published digital work of the creator with one file.
 */
function cartWork(Creator $creator, int $price = 800): Product
{
    $work = Product::factory()->digital()->for($creator)->create(['price' => $price]);
    ProductFile::factory()->for($work)->create();

    return $work;
}

it('keeps a digital work at quantity one', function () {
    $work = cartWork($this->creator);
    $cart = app(Cart::class);

    $cart->add($this->buyer, $work->variants()->firstOrFail(), 3);
    $cart->add($this->buyer, $work->variants()->firstOrFail(), 1);

    $item = CartItem::query()->sole();
    expect($item->quantity)->toBe(1);
    expect(fn () => $cart->setQuantity($item, 2))->toThrow(ValidationException::class);
    expect($item->refresh()->quantity)->toBe(1);
});

it('refuses to add a purchased work', function () {
    $work = cartWork($this->creator);
    Order::factory()->paid()->withItem($work)->create(['user_id' => $this->buyer->id, 'creator_id' => $this->creator->id]);

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $work->variants()->value('id'), 'quantity' => 1])
        ->assertSessionHasErrors(['cart' => '購入済みの作品です']);

    expect(CartItem::count())->toBe(0);
});

it('refuses to add a work without files', function () {
    $work = Product::factory()->digital()->for($this->creator)->create();

    $this->actingAs($this->buyer)->post(route('cart.items.store'), ['product_variant_id' => $work->variants()->value('id'), 'quantity' => 1])
        ->assertSessionHasErrors(['cart' => '現在購入できません']);

    expect(CartItem::count())->toBe(0);
});

it('charges no shipping for a digital-only group', function () {
    $work = cartWork($this->creator, 800);
    app(Cart::class)->add($this->buyer, $work->variants()->firstOrFail(), 1);

    $group = app(Cart::class)->groups($this->buyer)[0];

    expect($group['shipping_fee'])->toBe(0)
        ->and($group['total'])->toBe(800)
        ->and($group['purchasable'])->toBeTrue();

    $this->actingAs($this->buyer)->get(route('cart.index'))
        ->assertInertia(fn (Assert $page) => $page->where('groups.0.items.0.kind', 'digital')->where('groups.0.shipping_fee', 0));
});

it('charges shipping when a group mixes kinds', function () {
    $cart = app(Cart::class);
    $cart->add($this->buyer, cartWork($this->creator, 800)->variants()->firstOrFail(), 1);
    $cart->add($this->buyer, Product::factory()->for($this->creator)->create(['price' => 1000])->variants()->firstOrFail(), 1);

    $group = $cart->groups($this->buyer)[0];

    expect($group['shipping_fee'])->toBe(600)->and($group['total'])->toBe(2400);
});

it('refuses a cart group with an already purchased work', function () {
    $work = cartWork($this->creator);
    $cart = app(Cart::class);
    $cart->add($this->buyer, $work->variants()->firstOrFail(), 1);

    Order::factory()->paid()->withItem($work)->create(['user_id' => $this->buyer->id, 'creator_id' => $this->creator->id]);

    $group = $cart->groups($this->buyer)[0];
    expect($group['purchasable'])->toBeFalse()
        ->and($cart->refusal($this->buyer, $group))->toBe('購入済みの作品です');
});

it('marks a group whose work lost its files as not purchasable', function () {
    $work = cartWork($this->creator);
    app(Cart::class)->add($this->buyer, $work->variants()->firstOrFail(), 1);
    $work->files()->delete();

    $group = app(Cart::class)->groups($this->buyer)[0];
    expect($group['purchasable'])->toBeFalse()
        ->and(app(Cart::class)->refusal($this->buyer, $group))->toBe('現在購入できません');
});
