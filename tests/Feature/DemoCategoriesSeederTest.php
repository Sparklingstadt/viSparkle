<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\ProductCategory;
use Database\Seeders\DemoCategoriesSeeder;

it('seeds demo categories once and assigns them to demo goods', function () {
    $creator = Creator::factory()->create();
    $make = fn (string $name, array $extra = []) => Product::factory()->for($creator)->create(['name' => $name, ...$extra]);

    $stand = $make('アクリルスタンドA');
    $cheki = $make('ランダムチェキ（全4種）');
    $badge = $make('缶バッジ');
    $shirt = $make('Tシャツ');
    $rough = $make('ラフ画集');
    $other = $make('よくわからない商品');
    $manual = ProductCategory::factory()->create(['slug' => 'manual']);
    $kept = $make('缶バッジ', ['product_category_id' => $manual->id]);

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
