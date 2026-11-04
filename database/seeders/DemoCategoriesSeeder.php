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
 * Uses no model factories, so it also runs on production. Insert-only and safe to re-run: a category that exists
 * already, by slug or else by name, is used as it is (an admin's rename or reorder stays), and only a missing one is
 * created, at the end of the list. Only the products of the demo creators (those DemoProductsSeeder stocks) are
 * touched, and only while they have no category: a real creator's product with the same name, or a category set by
 * hand, is left alone.
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
        foreach (self::CATEGORIES as $definition) {
            $category = ProductCategory::where('slug', $definition['slug'])->first()
                ?? ProductCategory::where('name', $definition['name'])->first()
                ?? ProductCategory::create([
                    'slug' => $definition['slug'],
                    'name' => $definition['name'],
                    'sort_order' => ProductCategory::nextSortOrder(),
                ]);

            foreach ($definition['products'] as $name) {
                $query = Product::whereNull('product_category_id')
                    ->whereHas('creator', fn ($creators) => $creators->whereIn('slug', DemoProductsSeeder::creatorSlugs()));

                // Names are matched with LIKE (or =), so MySQL's utf8mb4_unicode_ci folds kana variants; they are chosen for the demo names only.
                if (str_ends_with($name, '*')) {
                    $query->where('name', 'like', substr($name, 0, -1).'%');
                } else {
                    $query->where('name', $name);
                }

                // toBase() keeps updated_at untouched.
                $query->toBase()->update(['product_category_id' => $category->id]);
            }
        }
    }
}
