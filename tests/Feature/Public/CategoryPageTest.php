<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('shows the active category on the product page', function () {
    $category = ProductCategory::factory()->create(['name' => 'ステッカー', 'slug' => 'sticker']);
    $product = Product::factory()->create(['product_category_id' => $category->id]);

    $url = route('shop.products.show', [$product->creator, $product]);

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('product.category.slug', 'sticker')
        ->where('product.category.name', 'ステッカー'));

    $category->forceFill(['archived_at' => now()])->save();

    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('product.category', null));
});

it('lists only listed products of open creators', function () {
    $category = ProductCategory::factory()->create();
    $other = ProductCategory::factory()->create();
    $shown = Product::factory()->create(['product_category_id' => $category->id]);
    Product::factory()->unpublished()->create(['product_category_id' => $category->id]);
    Product::factory()->create(['product_category_id' => $category->id, 'hidden_at' => now()]);
    Product::factory()->create(['product_category_id' => $category->id])->delete();
    Product::factory()->for(Creator::factory()->closed())->create(['product_category_id' => $category->id]);
    Product::factory()->for(Creator::factory()->for(User::factory()->suspended()))->create(['product_category_id' => $category->id]);
    Product::factory()->create(['product_category_id' => $other->id]);
    Product::factory()->create();

    $this->get(route('categories.show', $category))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/category')
            ->where('category.slug', $category->slug)
            ->has('categories', 2)
            ->has('products.data', 1)
            ->where('products.data.0.id', $shown->id)
            ->where('products.data.0.creator.slug', $shown->creator->slug)
            ->where('products.data.0.liked', false));
});

it('orders products newest first and pages by 24', function () {
    $category = ProductCategory::factory()->create();
    $products = Product::factory()->count(25)->create(['product_category_id' => $category->id]);

    $this->get(route('categories.show', $category))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 24)
            ->where('products.data.0.id', $products->last()->id)
            ->where('products.total', 25));

    $this->get(route('categories.show', [$category, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $products->first()->id));
});

it('returns 404 for an archived or unknown category', function () {
    $archived = ProductCategory::factory()->archived()->create();

    $this->get(route('categories.show', $archived))->assertNotFound();
    $this->get('/categories/nope')->assertNotFound();
});
