<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * The six product categories of the demo shop (アクリルグッズ, 缶バッジ・チェキ, アパレル・布もの, イラスト集, 音楽,
 * 本・同人誌), keyed by slug and ordered as listed, plus the category of every demo product that has none yet. A
 * product is matched by its name: an exact name, or a prefix for the entries ending in "*" (アクリルスタンド* covers
 * every acrylic stand).
 *
 * Uses no model factories, so it also runs on production. Safe to re-run: the categories are updated by slug, and a
 * product that already has a category (set by hand, for one) is left alone.
 */
class DemoCategoriesSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, name: string, products: list<string>}>
     */
    private const CATEGORIES = [
        ['slug' => 'acrylic', 'name' => 'アクリルグッズ', 'products' => ['アクリルスタンド*', 'アクリルチャーム']],
        ['slug' => 'badges-cheki', 'name' => '缶バッジ・チェキ', 'products' => ['缶バッジ', 'ランダムチェキ*']],
        ['slug' => 'apparel', 'name' => 'アパレル・布もの', 'products' => ['Tシャツ', 'タペストリー', 'パスケース']],
        ['slug' => 'illustration', 'name' => 'イラスト集', 'products' => ['ラフ画集']],
        ['slug' => 'music', 'name' => '音楽', 'products' => []],
        ['slug' => 'books', 'name' => '本・同人誌', 'products' => []],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $index => $definition) {
            $category = ProductCategory::updateOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'sort_order' => $index],
            );

            foreach ($definition['products'] as $name) {
                $query = Product::whereNull('product_category_id');

                if (str_ends_with($name, '*')) {
                    $query->where('name', 'like', substr($name, 0, -1).'%');
                } else {
                    $query->where('name', $name);
                }

                $query->update(['product_category_id' => $category->id]);
            }
        }
    }
}
