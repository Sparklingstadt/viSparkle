<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Database\Seeder;

/**
 * The four post categories of the demo creators (メイキング, 漫画, イラスト, 日記・お知らせ), keyed by slug and ordered
 * as listed, plus the category of every demo post that has none yet. A post is matched by its title: it falls in the
 * first category, in the order listed, whose strings the title contains (第1話 はじめまして contains "話 ", so it is
 * a 漫画).
 *
 * Uses no model factories, so it also runs on production. Insert-only and safe to re-run: a category that exists
 * already, by slug or else by name, is used as it is (an admin's rename or reorder stays), and only a missing one is
 * created, at the end of the list. Only the posts of the demo creators (those DemoProductsSeeder stocks) are touched,
 * and only while they have no category: a real creator's post with the same title, or a category set by hand, is
 * left alone.
 */
class DemoPostCategoriesSeeder extends Seeder
{
    /**
     * @var list<array{slug: string, name: string, titles: list<string>}>
     */
    private const CATEGORIES = [
        ['slug' => 'making', 'name' => 'メイキング', 'titles' => ['メイキング', '線画から仕上げまで', '制作日記', '開発日誌', '動画で解説']],
        ['slug' => 'manga', 'name' => '漫画', 'titles' => ['話 ']],
        ['slug' => 'illustration', 'name' => 'イラスト', 'titles' => ['ラフ', 'カット', '壁紙', '冬の灯り', '夕暮れ', '設定資料']],
        ['slug' => 'diary', 'name' => '日記・お知らせ', 'titles' => ['ご挨拶', 'ごあいさつ', 'お知らせ', '始めました', '更新しました', '公開しました']],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $definition) {
            $category = PostCategory::where('slug', $definition['slug'])->first()
                ?? PostCategory::where('name', $definition['name'])->first()
                ?? PostCategory::create([
                    'slug' => $definition['slug'],
                    'name' => $definition['name'],
                    'sort_order' => PostCategory::nextSortOrder(),
                ]);

            foreach ($definition['titles'] as $needle) {
                // Needles are matched with LIKE, so MySQL's utf8mb4_unicode_ci folds kana variants; they are chosen for the demo titles only.
                // toBase() keeps updated_at, which the public post page shows as 最終更新日.
                Post::whereNull('post_category_id')
                    ->whereHas('creator', fn ($creators) => $creators->whereIn('slug', DemoProductsSeeder::creatorSlugs()))
                    ->where('title', 'like', '%'.addcslashes($needle, '\\%_').'%')
                    ->toBase()
                    ->update(['post_category_id' => $category->id]);
            }
        }
    }
}
