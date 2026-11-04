<?php

use App\Models\Creator;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('shows the active category on the post page', function () {
    $category = PostCategory::factory()->create(['name' => 'イラスト', 'slug' => 'illust']);
    $post = Post::factory()->published()->create(['post_category_id' => $category->id]);

    $this->get(route('posts.show', [$post->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.category', ['id' => $category->id, 'name' => 'イラスト', 'slug' => 'illust']));

    $category->forceFill(['archived_at' => now()])->save();

    $this->get(route('posts.show', [$post->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page->where('post.category', null));
});

it('shows the category on a locked paid post too', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->published()->requiring(500)->create(['post_category_id' => $category->id]);

    $this->get(route('posts.show', [$post->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.locked', true)
            ->where('post.category.slug', $category->slug));
});

it('lists only free published posts of active members', function () {
    $category = PostCategory::factory()->create();
    $other = PostCategory::factory()->create();
    $shown = Post::factory()->published()->create(['post_category_id' => $category->id]);

    Post::factory()->published()->requiring(500)->create(['post_category_id' => $category->id]);
    Post::factory()->draft()->create(['post_category_id' => $category->id]);
    Post::factory()->draft()->create(['post_category_id' => $category->id, 'published_at' => now()->addDay()]);
    Post::factory()->published()->hidden()->create(['post_category_id' => $category->id]);
    Post::factory()->published()->create([
        'post_category_id' => $category->id,
        'creator_id' => Creator::factory()->for(User::factory()->suspended()),
    ]);
    Post::factory()->published()->create(['post_category_id' => $other->id]);
    Post::factory()->published()->create(['post_category_id' => null]);

    $this->get(route('post-categories.show', $category->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/post-category')
            ->where('category.slug', $category->slug)
            ->has('categories', 2)
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $shown->id));
});

it('orders posts newest first and pages by 20', function () {
    $category = PostCategory::factory()->create();
    foreach (range(1, 21) as $i) {
        Post::factory()->published()->create([
            'post_category_id' => $category->id,
            'published_at' => now()->subMinutes(30 - $i),
        ]);
    }
    $oldest = Post::query()->orderBy('published_at')->first();

    $this->get(route('post-categories.show', $category->slug))
        ->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 20)
            ->where('posts.total', 21)
            ->where('posts.data.0.id', Post::query()->orderByDesc('published_at')->first()->id));

    $this->get(route('post-categories.show', [$category->slug, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $oldest->id));
});

it('returns 404 for an archived or unknown category', function () {
    $archived = PostCategory::factory()->archived()->create();

    $this->get(route('post-categories.show', $archived->slug))->assertNotFound();
    $this->get('/post-categories/nope')->assertNotFound();
});
