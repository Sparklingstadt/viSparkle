<?php

use App\Models\Plan;
use App\Models\Post;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('shows a free post to everyone', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/post')
            ->where('post.locked', false)
            ->where('creator.name', config('visparkle.creator.name'))
            ->has('post.body'));
});

it('shows both the published and last updated dates', function () {
    $post = Post::factory()->published()->requiring(500)->create([
        'published_at' => '2026-10-01 10:00:00',
        'updated_at' => '2026-10-15 10:00:00',
    ]);

    $this->get(route('posts.show', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.locked', true)
            ->where('post.published_at', fn ($at) => str_starts_with($at, '2026-10-01'))
            ->where('post.updated_at', fn ($at) => str_starts_with($at, '2026-10-15')));
});

it('404s drafts for others and shows them to the creator', function () {
    $post = Post::factory()->draft()->create();

    $this->get(route('posts.show', $post))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('posts.show', $post))->assertNotFound();
    $this->actingAs(User::factory()->creator()->create())->get(route('posts.show', $post))->assertOk();
});

it('offers accepting plans that unlock a locked post', function () {
    Plan::factory()->price(300)->create();
    Plan::factory()->price(1000)->create();
    Plan::factory()->price(2000)->notAccepting()->create();
    $post = Post::factory()->published()->requiring(500)->create();

    $this->get(route('posts.show', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.locked', true)
            ->where('post.required_price', 500)
            ->has('plans', 2)
            ->where('plans.0.price', 300)
            ->where('viewerSupportPrice', null));
});

it('shows up to four other posts, newest first', function () {
    $post = Post::factory()->published()->create();
    $others = collect(range(1, 5))->map(fn (int $day) => Post::factory()->published()->create(['published_at' => now()->subDays($day)]));
    Post::factory()->draft()->create();

    $this->get(route('posts.show', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->has('morePosts', 4)
            ->where('morePosts.0.id', $others[0]->id)
            ->where('morePosts.3.id', $others[3]->id));
});
