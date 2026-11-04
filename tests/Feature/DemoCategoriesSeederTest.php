<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\DemoCategoriesSeeder;

function demoProduct(string $name, array $extra = [], string $creatorSlug = 'demo-creator'): Product
{
    $creator = Creator::where('slug', $creatorSlug)->first() ?? Creator::factory()->create(['slug' => $creatorSlug]);

    return Product::factory()->for($creator)->create(['name' => $name, ...$extra]);
}

it('seeds demo categories once and assigns them to demo goods', function () {
    $stand = demoProduct('アクリルスタンドA');
    $cheki = demoProduct('ランダムチェキ（全4種）');
    $badge = demoProduct('缶バッジ');
    $shirt = demoProduct('Tシャツ');
    $rough = demoProduct('ラフ画集');
    $other = demoProduct('よくわからない商品');
    $manual = ProductCategory::factory()->create(['slug' => 'manual']);
    $kept = demoProduct('缶バッジ', ['product_category_id' => $manual->id]);

    $this->seed(DemoCategoriesSeeder::class);
    $this->seed(DemoCategoriesSeeder::class);

    expect(ProductCategory::where('slug', '!=', 'manual')->count())->toBe(6)
        ->and(ProductCategory::where('slug', 'acrylic')->count())->toBe(1);

    $slugOf = fn (Product $product) => $product->fresh()->category?->slug;
    expect($slugOf($stand))->toBe('acrylic')
        ->and($slugOf($cheki))->toBe('badges-cheki')
        ->and($slugOf($badge))->toBe('badges-cheki')
        ->and($slugOf($shirt))->toBe('apparel')
        ->and($slugOf($rough))->toBe('illustration')
        ->and($other->fresh()->product_category_id)->toBeNull()
        ->and($slugOf($kept))->toBe('manual');
});

it('leaves the products of real creators alone', function () {
    $demo = demoProduct('Tシャツ');
    $real = demoProduct('Tシャツ', creatorSlug: 'real-creator');

    $this->seed(DemoCategoriesSeeder::class);

    expect($demo->fresh()->category?->slug)->toBe('apparel')
        ->and($real->fresh()->product_category_id)->toBeNull();
});

it('keeps an admin rename and reorder on a re-run', function () {
    $this->seed(DemoCategoriesSeeder::class);
    ProductCategory::where('slug', 'acrylic')->update(['name' => 'アクリル', 'sort_order' => 99]);

    $this->seed(DemoCategoriesSeeder::class);

    $acrylic = ProductCategory::where('slug', 'acrylic')->first();
    expect($acrylic->name)->toBe('アクリル')
        ->and($acrylic->sort_order)->toBe(99)
        ->and(ProductCategory::count())->toBe(6);
});

it('reuses an existing category with the same name under another slug', function () {
    $existing = ProductCategory::factory()->create(['slug' => 'music-cd', 'name' => '音楽']);

    $this->seed(DemoCategoriesSeeder::class);

    expect(ProductCategory::where('name', '音楽')->count())->toBe(1)
        ->and(ProductCategory::where('slug', 'music')->exists())->toBeFalse()
        ->and(ProductCategory::count())->toBe(6)
        ->and($existing->fresh()->slug)->toBe('music-cd');
});
