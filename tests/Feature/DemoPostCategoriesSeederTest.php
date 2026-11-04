<?php

use App\Models\Creator;
use App\Models\Post;
use App\Models\PostCategory;
use Database\Seeders\DemoPostCategoriesSeeder;

function demoPost(string $title, array $extra = [], string $creatorSlug = 'demo-creator'): Post
{
    $creator = Creator::where('slug', $creatorSlug)->first() ?? Creator::factory()->create(['slug' => $creatorSlug]);

    return Post::factory()->for($creator)->create(['title' => $title, ...$extra]);
}

it('seeds demo post categories once and assigns them to demo posts', function () {
    $making = demoPost('メイキング：春の絵');
    $manga = demoPost('第1話 はじめまして');
    $illustration = demoPost('今月のラフ');
    $diary = demoPost('ご挨拶');
    $other = demoPost('よくわからない投稿');
    $manual = PostCategory::factory()->create(['slug' => 'manual']);
    $kept = demoPost('ご挨拶', ['post_category_id' => $manual->id]);

    $this->seed(DemoPostCategoriesSeeder::class);
    $this->seed(DemoPostCategoriesSeeder::class);

    expect(PostCategory::where('slug', '!=', 'manual')->count())->toBe(4)
        ->and(PostCategory::where('slug', 'making')->count())->toBe(1);

    $slugOf = fn (Post $post) => $post->fresh()->category?->slug;
    expect($slugOf($making))->toBe('making')
        ->and($slugOf($manga))->toBe('manga')
        ->and($slugOf($illustration))->toBe('illustration')
        ->and($slugOf($diary))->toBe('diary')
        ->and($other->fresh()->post_category_id)->toBeNull()
        ->and($slugOf($kept))->toBe('manual');
});

it('gives the earlier rule precedence when a title matches several', function () {
    $post = demoPost('メイキング動画 第3話 ラフ');

    $this->seed(DemoPostCategoriesSeeder::class);

    expect($post->fresh()->category?->slug)->toBe('making');
});

it('leaves the posts of real creators alone', function () {
    $demo = demoPost('ご挨拶');
    $real = demoPost('ご挨拶', creatorSlug: 'real-creator');

    $this->seed(DemoPostCategoriesSeeder::class);

    expect($demo->fresh()->category?->slug)->toBe('diary')
        ->and($real->fresh()->post_category_id)->toBeNull();
});

it('keeps an admin rename and reorder on a re-run', function () {
    $this->seed(DemoPostCategoriesSeeder::class);
    PostCategory::where('slug', 'making')->update(['name' => '制作', 'sort_order' => 99]);

    $this->seed(DemoPostCategoriesSeeder::class);

    $making = PostCategory::where('slug', 'making')->first();
    expect($making->name)->toBe('制作')
        ->and($making->sort_order)->toBe(99)
        ->and(PostCategory::count())->toBe(4);
});

it('reuses an existing category with the same name under another slug', function () {
    $existing = PostCategory::factory()->create(['slug' => 'comic', 'name' => '漫画']);
    $post = demoPost('第1話 はじめまして');

    $this->seed(DemoPostCategoriesSeeder::class);

    expect(PostCategory::where('name', '漫画')->count())->toBe(1)
        ->and(PostCategory::where('slug', 'manga')->exists())->toBeFalse()
        ->and(PostCategory::count())->toBe(4)
        ->and($existing->fresh()->slug)->toBe('comic')
        ->and($post->fresh()->post_category_id)->toBe($existing->id);
});
