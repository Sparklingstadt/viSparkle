<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\ProductCategory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->creator = Creator::factory()->payable()->create();
    $this->actingAs($this->creator->user);
});

/**
 * @return array<string, mixed>
 */
function categoryPayload(string $kind, array $overrides = []): array
{
    $base = ['kind' => $kind, 'name' => '商品', 'price' => 800, 'is_published' => false];

    return $kind === 'digital'
        ? [...$base, ...$overrides]
        : [...$base, 'variants' => [['id' => null, 'name' => null, 'stock' => 5]], ...$overrides];
}

it('creates a product with a category', function (string $kind) {
    $category = ProductCategory::factory()->create();

    $this->post(route('creator.products.store'), categoryPayload($kind, ['product_category_id' => $category->id]))
        ->assertSessionHasNoErrors();

    expect($this->creator->products()->sole()->product_category_id)->toBe($category->id);
})->with(['goods' => 'physical', 'digital' => 'digital']);

it('creates a product without a category when none is sent', function () {
    $this->post(route('creator.products.store'), categoryPayload('digital'))->assertSessionHasNoErrors();

    expect($this->creator->products()->sole()->product_category_id)->toBeNull();
});

it('changes and clears the category', function () {
    [$a, $b] = ProductCategory::factory()->count(2)->create();
    $product = Product::factory()->digital()->for($this->creator)->create(['product_category_id' => $a->id]);

    $this->put(route('creator.products.update', $product), categoryPayload('digital', ['product_category_id' => $b->id]))
        ->assertSessionHasNoErrors();
    expect($product->fresh()->product_category_id)->toBe($b->id);

    $this->put(route('creator.products.update', $product), categoryPayload('digital', ['product_category_id' => '']))
        ->assertSessionHasNoErrors();
    expect($product->fresh()->product_category_id)->toBeNull();
});

it('offers active categories in order on the form', function () {
    $second = ProductCategory::factory()->create(['name' => '二番目', 'sort_order' => 2]);
    $first = ProductCategory::factory()->create(['name' => '一番目', 'sort_order' => 1]);
    ProductCategory::factory()->archived()->create();

    $this->get(route('creator.products.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('categories', 2)
            ->where('categories.0', ['id' => $first->id, 'name' => '一番目', 'archived' => false])
            ->where('categories.1', ['id' => $second->id, 'name' => '二番目', 'archived' => false]));
});

it('rejects an archived or missing category', function (?int $id) {
    $archived = ProductCategory::factory()->archived()->create();

    $this->post(route('creator.products.store'), categoryPayload('digital', ['product_category_id' => $id ?? $archived->id]))
        ->assertSessionHasErrors(['product_category_id' => 'カテゴリが正しくありません']);

    $this->post(route('creator.products.store'), categoryPayload('digital', ['product_category_id' => 'abc']))
        ->assertSessionHasErrors(['product_category_id' => 'カテゴリが正しくありません']);

    expect($this->creator->products()->count())->toBe(0);
})->with(['archived' => null, 'missing' => 999999]);

it('keeps an archived category the product already has', function () {
    $category = ProductCategory::factory()->create();
    $active = ProductCategory::factory()->create();
    $product = Product::factory()->digital()->for($this->creator)->create(['product_category_id' => $category->id]);
    $category->forceFill(['archived_at' => now()])->save();

    $this->put(route('creator.products.update', $product), categoryPayload('digital', ['name' => '新しい名前', 'product_category_id' => $category->id]))
        ->assertSessionHasNoErrors();
    expect($product->fresh())->name->toBe('新しい名前')->product_category_id->toBe($category->id);

    $this->get(route('creator.products.edit', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.category_id', $category->id)
            ->has('categories', 2)
            ->where('categories.0.id', $active->id)
            ->where('categories.1', ['id' => $category->id, 'name' => $category->name, 'archived' => true]));
});

it('rejects an archived category the product does not have', function () {
    $held = ProductCategory::factory()->create();
    $other = ProductCategory::factory()->archived()->create();
    $product = Product::factory()->digital()->for($this->creator)->create(['product_category_id' => $held->id]);

    $this->put(route('creator.products.update', $product), categoryPayload('digital', ['product_category_id' => $other->id]))
        ->assertSessionHasErrors(['product_category_id' => 'カテゴリが正しくありません']);
    expect($product->fresh()->product_category_id)->toBe($held->id);
});
