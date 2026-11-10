<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('shows a free post to everyone', function () {
    $post = Post::factory()->published()->create();

    $this->get(route('posts.show', [$post->creator, $post]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/post')
            ->where('post.locked', false)
            ->where('post.creator.slug', $post->creator->slug)
            ->has('post.body'));
});

it('shows both the published and last updated dates', function () {
    $post = Post::factory()->published()->requiring(500)->create([
        'published_at' => '2026-10-01 10:00:00',
        'updated_at' => '2026-10-15 10:00:00',
    ]);

    $this->get(route('posts.show', [$post->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.locked', true)
            ->where('post.published_at', fn ($at) => str_starts_with($at, '2026-10-01'))
            ->where('post.updated_at', fn ($at) => str_starts_with($at, '2026-10-15')));
});

it('404s drafts for others and shows them to the author', function () {
    $post = Post::factory()->draft()->create();

    $this->get(route('posts.show', [$post->creator, $post]))->assertNotFound();
    $this->actingAs($post->creator->user)->get(route('posts.show', [$post->creator, $post]))->assertOk();
});

it('404s a post under the wrong creator slug', function () {
    $post = Post::factory()->published()->create();
    $other = Creator::factory()->create();

    $this->get(route('posts.show', [$other, $post]))->assertNotFound();
});

it('404s an unknown creator', function () {
    $this->get('/@nobody-here')->assertNotFound();
});

it('404s an uppercase slug', function () {
    $creator = Creator::factory()->create(['slug' => 'alice']);

    $this->get('/@alice')->assertOk();
    $this->get('/@Alice')->assertNotFound();
    $this->get('/@ALICE')->assertNotFound();
});

it('offers accepting plans that unlock a locked post', function () {
    $creator = Creator::factory()->create();
    Plan::factory()->for($creator)->price(300)->create();
    Plan::factory()->for($creator)->price(1000)->create();
    Plan::factory()->for($creator)->price(2000)->notAccepting()->create();
    $post = Post::factory()->for($creator)->published()->requiring(500)->create();

    $this->get(route('posts.show', [$creator, $post]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.locked', true)
            ->where('post.required_price', 500)
            ->has('plans', 2)
            ->where('plans.0.price', 300)
            ->where('viewerSupportPrice', null));
});

it('shows up to four other posts by the same creator, newest first', function () {
    $post = Post::factory()->published()->create();
    $others = collect(range(1, 5))->map(fn (int $day) => Post::factory()->for($post->creator)->published()->create(['published_at' => now()->subDays($day)]));
    Post::factory()->for($post->creator)->draft()->create();
    Post::factory()->published()->create();

    $this->get(route('posts.show', [$post->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('morePosts', 4)
            ->where('morePosts.0.id', $others[0]->id)
            ->where('morePosts.3.id', $others[3]->id)
            ->where('morePosts.0.creator.slug', $post->creator->slug));
});
