<?php

use App\Models\Product;
use App\Models\ProductCategory;

it('lists active categories in order', function () {
    $b = ProductCategory::factory()->create(['name' => 'B', 'sort_order' => 1]);
    $a = ProductCategory::factory()->create(['name' => 'A', 'sort_order' => 0]);
    ProductCategory::factory()->archived()->create(['sort_order' => 2]);

    expect(ProductCategory::activeSummaries())->toBe([$a->toSummary(), $b->toSummary()]);
});

it('shows only an active category publicly', function () {
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create();
    expect($product->publicCategory()?->id)->toBe($product->product_category_id);

    $product->category->forceFill(['archived_at' => now()])->save();
    expect($product->fresh()->publicCategory())->toBeNull();
});

it('unsets the category when the category is deleted', function () {
    $product = Product::factory()->for(ProductCategory::factory(), 'category')->create();
    $product->category->delete();
    expect($product->fresh()->product_category_id)->toBeNull();
});

it('puts a new category last', function () {
    expect(ProductCategory::nextSortOrder())->toBe(0);
    ProductCategory::factory()->create(['sort_order' => 4]);
    expect(ProductCategory::nextSortOrder())->toBe(5);
});
