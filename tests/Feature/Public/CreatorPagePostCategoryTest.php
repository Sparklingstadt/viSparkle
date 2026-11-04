<?php

use App\Models\Creator;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('filters paid posts too and keeps them locked', function () {
    $creator = Creator::factory()->create();
    $category = PostCategory::factory()->create();
    $other = PostCategory::factory()->create();
    $free = Post::factory()->for($creator)->published()->create(['post_category_id' => $category->id]);
    $paid = Post::factory()->for($creator)->published()->requiring(500)->create(['post_category_id' => $category->id]);
    Post::factory()->for($creator)->published()->create(['post_category_id' => $other->id]);
    Post::factory()->for($creator)->published()->create();

    $this->get(route('creators.show', [$creator, 'post_category' => $category->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('postCategory', $category->slug)
            ->has('posts.data', 2)
            ->where('posts.data', fn ($posts) => collect($posts)->pluck('id')->sort()->values()->all() === [$free->id, $paid->id]
                && collect($posts)->firstWhere('id', $paid->id)['locked'] === true
                && collect($posts)->firstWhere('id', $free->id)['locked'] === false));
});

it('offers only categories used by published posts', function () {
    $creator = Creator::factory()->create();
    $used = PostCategory::factory()->create(['sort_order' => 2]);
    $first = PostCategory::factory()->create(['sort_order' => 1]);
    $draftOnly = PostCategory::factory()->create();
    $futureOnly = PostCategory::factory()->create();
    $archived = PostCategory::factory()->archived()->create();
    $othersOnly = PostCategory::factory()->create();

    Post::factory()->for($creator)->published()->create(['post_category_id' => $used->id]);
    Post::factory()->for($creator)->published()->create(['post_category_id' => $first->id]);
    Post::factory()->for($creator)->draft()->create(['post_category_id' => $draftOnly->id]);
    Post::factory()->for($creator)->draft()->create(['post_category_id' => $futureOnly->id, 'published_at' => now()->addDay()]);
    Post::factory()->for($creator)->published()->create(['post_category_id' => $archived->id]);
    Post::factory()->published()->create(['post_category_id' => $othersOnly->id]);

    $this->get(route('creators.show', $creator))
        ->assertInertia(fn (Assert $page) => $page
            ->where('postCategory', null)
            ->where('postCategories', fn ($categories) => collect($categories)->pluck('slug')->all() === [$first->slug, $used->slug]));

    $this->get(route('creators.show', [$creator, 'post_category' => $archived->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('postCategory', null)->has('posts.data', 3));
    $this->get(route('creators.show', [$creator, 'post_category' => $othersOnly->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('postCategory', null)->has('posts.data', 3));
});

it('ignores an unknown post category', function () {
    $creator = Creator::factory()->create();
    $category = PostCategory::factory()->create();
    Post::factory()->for($creator)->published()->create(['post_category_id' => $category->id]);
    Post::factory()->for($creator)->published()->create();

    $this->get(route('creators.show', [$creator, 'post_category' => 'nope']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('postCategory', null)->has('posts.data', 2));
    $this->get(route('creators.show', [$creator, 'post_category' => ['x']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('postCategory', null)->has('posts.data', 2));
});

it('keeps the post category in pagination links', function () {
    $creator = Creator::factory()->create();
    $category = PostCategory::factory()->create();
    Post::factory()->for($creator)->published()->count(21)->create(['post_category_id' => $category->id]);

    $this->get(route('creators.show', [$creator, 'post_category' => $category->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 20)
            ->where('posts.next_page_url', fn ($url) => str_contains($url, 'post_category='.$category->slug)));
});

it('leaves the shop tab alone', function () {
    $creator = Creator::factory()->create();
    $category = PostCategory::factory()->create();
    Post::factory()->for($creator)->published()->create(['post_category_id' => $category->id]);
    Product::factory()->for($creator)->create();

    $this->get(route('creators.show', [$creator, 'tab' => 'shop', 'post_category' => $category->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'shop')
            ->where('shop.visible', true)
            ->has('shop.products', 1));
});
