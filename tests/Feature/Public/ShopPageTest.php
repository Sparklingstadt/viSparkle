<?php

use App\Enums\OrderStatus;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows listed products in the shop tab', function () {
    $creator = Creator::factory()->payable()->create(['shop_notice' => '発送は週末です']);
    Product::factory()->for($creator)->soldOut()->create(['name' => 'ステッカー']);
    Product::factory()->for($creator)->create(['name' => 'マグカップ', 'price' => 1800]);
    Product::factory()->for($creator)->unpublished()->create();
    Product::factory()->for($creator)->create(['hidden_at' => now()]);
    Product::factory()->for($creator)->create()->delete();

    $this->get(route('creators.show', [$creator, 'tab' => 'shop']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/creator')
            ->where('tab', 'shop')
            ->where('shop.visible', true)
            ->where('shop.notice', '発送は週末です')
            ->has('shop.products', 2)
            ->where('shop.products.0.name', 'マグカップ')
            ->where('shop.products.0.sold_out', false)
            ->where('shop.products.1.sold_out', true)
            ->where('can_buy', true));
});

it('keeps the posts tab by default and does not compute the shop for it', function () {
    $creator = Creator::factory()->payable()->create();
    Product::factory()->for($creator)->create();

    $this->get(route('creators.show', $creator))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'posts')->where('shop.visible', true)->has('shop.products', 0));
});

it('shows no shop tab when there is no listed product', function () {
    $creator = Creator::factory()->payable()->create();
    Product::factory()->for($creator)->unpublished()->create();

    $this->get(route('creators.show', [$creator, 'tab' => 'shop']))
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'posts')->where('shop.visible', false)->has('shop.products', 0));
});

it('hides the shop of a closed or suspended creator', function () {
    $closed = Creator::factory()->payable()->closed()->create();
    $closedProduct = Product::factory()->for($closed)->create();
    $suspended = Creator::factory()->payable()->create();
    $suspendedProduct = Product::factory()->for($suspended)->create();
    $suspended->user->forceFill(['suspended_at' => now()])->save();

    $this->get(route('creators.show', [$closed, 'tab' => 'shop']))
        ->assertInertia(fn (Assert $page) => $page->where('shop.visible', false)->where('tab', 'posts'));
    $this->get(route('shop.products.show', [$closed, $closedProduct]))->assertNotFound();
    $this->get(route('shop.products.show', [$suspended, $suspendedProduct]))->assertNotFound();
});

it('shows products of a creator without payouts as not purchasable', function () {
    $creator = Creator::factory()->create();
    $product = Product::factory()->for($creator)->create();

    $this->get(route('creators.show', [$creator, 'tab' => 'shop']))
        ->assertInertia(fn (Assert $page) => $page->where('can_buy', false)->has('shop.products', 1));
    $this->get(route('shop.products.show', [$creator, $product]))
        ->assertInertia(fn (Assert $page) => $page->component('public/product')->where('can_buy', false));
});

it('shows a product page with the shipping fee and stock', function () {
    $creator = Creator::factory()->payable()->create(['shipping_fee' => 600]);
    $product = Product::factory()->for($creator)->create(['name' => 'マグカップ', 'price' => 1800, 'stock' => 3]);

    $this->get(route('shop.products.show', [$creator, $product]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/product')
            ->where('product.name', 'マグカップ')
            ->where('product.price', 1800)
            ->where('product.stock', 3)
            ->where('shipping_fee', 600)
            ->where('can_buy', true)
            ->where('is_owner', false));
});

it('404s an unpublished, hidden or deleted product', function () {
    $creator = Creator::factory()->payable()->create();
    $unpublished = Product::factory()->for($creator)->unpublished()->create();
    $hidden = Product::factory()->for($creator)->create(['hidden_at' => now()]);
    $deleted = Product::factory()->for($creator)->create();
    $deleted->delete();

    foreach ([$unpublished, $hidden, $deleted] as $product) {
        $this->get(route('shop.products.show', [$creator, $product]))->assertNotFound();
    }
});

it('404s a product of another creator', function () {
    $creator = Creator::factory()->payable()->create();
    $product = Product::factory()->for(Creator::factory()->payable())->create();

    $this->get(route('shop.products.show', [$creator, $product]))->assertNotFound();
});

it('lets the owner see the product but not buy it', function () {
    $creator = Creator::factory()->payable()->create();
    $product = Product::factory()->for($creator)->create();

    $this->actingAs($creator->user)->get(route('shop.products.show', [$creator, $product]))
        ->assertInertia(fn (Assert $page) => $page->where('is_owner', true));
});

it('shows a digital work with its files and no stock', function () {
    $creator = Creator::factory()->payable()->create();
    $work = Product::factory()->digital()->for($creator)->create(['name' => 'イラスト集']);
    ProductFile::factory()->for($work)->create(['original_name' => 'a.ZIP', 'size' => 1000, 'path' => 'creators/1/products/1/x.zip']);
    ProductFile::factory()->for($work)->create(['original_name' => 'b.pdf', 'size' => 500, 'path' => 'creators/1/products/1/y.pdf']);

    $this->get(route('shop.products.show', [$creator, $work]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.kind', 'digital')
            ->has('product.files', 2)
            ->where('product.files.0', ['extension' => 'zip', 'size' => 1000])
            ->where('product.files.1.extension', 'pdf')
            ->where('product.files_total_size', 1500)
            ->where('product.purchasable', true)
            ->where('purchased_order_id', null));

    $this->get(route('creators.show', [$creator, 'tab' => 'shop']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shop.products.0.kind', 'digital')
            ->where('shop.products.0.sold_out', false));
});

it('links a purchased work to its order', function () {
    $creator = Creator::factory()->payable()->create();
    $work = Product::factory()->digital()->for($creator)->create();
    ProductFile::factory()->for($work)->create();
    $buyer = User::factory()->create();
    $order = Order::factory()->paid()->for($buyer)->for($creator)->withItem($work)->create();

    $this->actingAs($buyer)->get(route('shop.products.show', [$creator, $work]))
        ->assertInertia(fn (Assert $page) => $page->where('purchased_order_id', $order->id));
});

it('shows a work without files as not purchasable', function () {
    $creator = Creator::factory()->payable()->create();
    $work = Product::factory()->digital()->for($creator)->create();

    $this->get(route('shop.products.show', [$creator, $work]))
        ->assertInertia(fn (Assert $page) => $page->where('product.purchasable', false));
});

it('sorts the shop by units sold in paid orders when popular is chosen', function () {
    $creator = Creator::factory()->payable()->create();
    $quiet = Product::factory()->for($creator)->create();
    $hit = Product::factory()->for($creator)->create();
    $refundedHit = Product::factory()->for($creator)->create();
    $newest = Product::factory()->for($creator)->create();
    Order::factory()->for($creator)->paid()->withItem($quiet)->create();
    Order::factory()->for($creator)->paid()->withItem($hit, 2)->create();
    Order::factory()->for($creator)->shipped()->withItem($hit)->create();
    Order::factory()->for($creator)->withItem($refundedHit, 5)->create();
    Order::factory()->for($creator)->paid()->state(['status' => OrderStatus::Refunded])->withItem($refundedHit, 5)->create();

    $this->get(route('creators.show', [$creator, 'tab' => 'shop', 'sort' => 'popular']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shop.sort', 'popular')
            ->where('shop.products.0.id', $hit->id)
            ->where('shop.products.1.id', $quiet->id)
            ->where('shop.products.2.id', $newest->id)
            ->where('shop.products.3.id', $refundedHit->id));
});

it('lists the shop newest first by default and for an unknown sort', function () {
    $creator = Creator::factory()->payable()->create();
    $old = Product::factory()->for($creator)->create();
    $new = Product::factory()->for($creator)->create();
    Order::factory()->for($creator)->paid()->withItem($old, 3)->create();

    foreach (['new', 'bogus'] as $sort) {
        $this->get(route('creators.show', [$creator, 'tab' => 'shop', 'sort' => $sort]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('shop.sort', 'new')
                ->where('shop.products.0.id', $new->id)
                ->where('shop.products.1.id', $old->id));
    }
});
