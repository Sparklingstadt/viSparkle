<?php

use App\Models\Post;
use App\Models\Product;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('defaults to newest posts', function () {
    $old = Post::factory()->published()->create(['published_at' => now()->subDays(2), 'view_count' => 50]);
    $new = Post::factory()->published()->create(['view_count' => 1]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', 'new')
            ->where('posts.0.id', $new->id)
            ->where('posts.1.id', $old->id));
});

it('sorts recent free posts by views when popular is chosen', function () {
    $quiet = Post::factory()->published()->create(['view_count' => 1]);
    $hit = Post::factory()->published()->create(['published_at' => now()->subDays(3), 'view_count' => 50]);
    Post::factory()->published()->create(['published_at' => now()->subDays(31), 'view_count' => 999]);
    Post::factory()->published()->requiring(500)->create(['view_count' => 999]);
    Post::factory()->published()->hidden()->create(['view_count' => 999]);
    Post::factory()->draft()->create(['view_count' => 999]);

    $this->get(route('home', ['sort' => 'popular']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', 'popular')
            ->has('posts', 2)
            ->where('posts.0.id', $hit->id)
            ->where('posts.1.id', $quiet->id));
});

it('shows at most eight public posts on the home page', function () {
    Post::factory()->published()->count(9)->create();

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->has('posts', 8));
});

it('lists new products by the date they were added', function () {
    $newest = Product::factory()->create(['created_at' => now()]);
    $older = Product::factory()->create(['created_at' => now()->subDay()]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.0.id', $newest->id)
            ->where('products.1.id', $older->id));
});

it('falls back to newest for an unknown sort', function () {
    $this->get(route('home', ['sort' => 'bogus']))
        ->assertInertia(fn (Assert $page) => $page->where('sort', 'new'));
});

it('counts a view once per session and ignores the author', function () {
    $post = Post::factory()->published()->create();
    $url = route('posts.show', [$post->creator, $post]);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    expect($post->fresh()->view_count)->toBe(1);

    $this->flushSession();
    $this->actingAs($post->creator->user)->get($url)->assertOk();
    expect($post->fresh()->view_count)->toBe(1);
});

it('does not count views of drafts or touch updated_at', function () {
    $draft = Post::factory()->draft()->create();
    $this->actingAs($draft->creator->user)->get(route('posts.show', [$draft->creator, $draft]));
    expect($draft->fresh()->view_count)->toBe(0);

    $post = Post::factory()->published()->create(['updated_at' => now()->subDay()]);
    $before = $post->updated_at;
    auth()->logout();
    $this->get(route('posts.show', [$post->creator, $post]))->assertOk();
    expect($post->fresh()->view_count)->toBe(1)
        ->and($post->fresh()->updated_at->equalTo($before))->toBeTrue();
});
