<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;

beforeEach(fn () => fakePrivateDisk());

it('shows posts of supported creators only, newest first', function () {
    $fan = User::factory()->create();
    $supported = Creator::factory()->create();
    Support::factory()->for($fan)->forPlan(Plan::factory()->for($supported)->price(500)->create())->active()->create();
    $ended = Creator::factory()->create();
    Support::factory()->for($fan)->forPlan(Plan::factory()->for($ended)->create())->ended()->create();

    $old = Post::factory()->for($supported)->published()->create(['published_at' => now()->subDay()]);
    $new = Post::factory()->for($supported)->published()->requiring(1000)->create();
    Post::factory()->for($supported)->draft()->create();
    Post::factory()->for($ended)->published()->create();
    Post::factory()->published()->create();

    $this->actingAs($fan)->get(route('feed'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fan/feed')
            ->has('posts.data', 2)
            ->where('posts.data.0.id', $new->id)
            ->where('posts.data.0.locked', true)
            ->where('posts.data.1.id', $old->id)
            ->missing('posts.data.0.body'));
});

it('redirects the dashboard to the feed', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertRedirect(route('feed'));
});

function supportFor(User $fan, Creator $creator): void
{
    Support::factory()->for($fan)->forPlan(Plan::factory()->for($creator)->price(500)->create())->active()->create();
}

it('shows free posts of followed creators in the all tab', function () {
    $fan = User::factory()->create();
    $followed = Creator::factory()->create();
    $followed->follow($fan);
    $free = Post::factory()->for($followed)->published()->create();
    Post::factory()->for($followed)->published()->requiring(500)->create();
    Post::factory()->for($followed)->draft()->create();

    $this->actingAs($fan)->get(route('feed'))
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'all')
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $free->id));
});

it('shows only supported creators in the supporting tab', function () {
    $fan = User::factory()->create();
    $supported = Creator::factory()->create();
    supportFor($fan, $supported);
    $followed = Creator::factory()->create();
    $followed->follow($fan);
    $mine = Post::factory()->for($supported)->published()->create();
    Post::factory()->for($followed)->published()->create();

    $this->actingAs($fan)->get(route('feed', ['tab' => 'supporting']))
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'supporting')
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $mine->id));
});

it('shows a post once when the creator is both supported and followed', function () {
    $fan = User::factory()->create();
    $creator = Creator::factory()->create();
    supportFor($fan, $creator);
    $creator->follow($fan);
    Post::factory()->for($creator)->published()->create();
    Post::factory()->for($creator)->published()->requiring(1000)->create();

    $this->actingAs($fan)->get(route('feed'))->assertInertia(fn ($page) => $page->has('posts.data', 2));
});

it('keeps supported creators in the feed after unfollowing', function () {
    $fan = User::factory()->create();
    $creator = Creator::factory()->create();
    supportFor($fan, $creator);
    $creator->unfollow($fan);
    Post::factory()->for($creator)->published()->create();
    Post::factory()->for($creator)->published()->requiring(1000)->create();

    $this->actingAs($fan)->get(route('feed'))->assertInertia(fn ($page) => $page->has('posts.data', 2));
});

it('hides followed creators whose owner is suspended', function () {
    $fan = User::factory()->create();
    $creator = Creator::factory()->for(User::factory()->suspended())->create();
    $creator->follow($fan);
    Post::factory()->for($creator)->published()->create();

    $this->actingAs($fan)->get(route('feed'))->assertInertia(fn ($page) => $page->has('posts.data', 0));
});

it('treats an unknown tab as all', function () {
    $this->actingAs(User::factory()->create())->get(route('feed', ['tab' => 'nope']))
        ->assertInertia(fn ($page) => $page->where('tab', 'all'));
});

it('keeps the tab in pagination links', function () {
    $fan = User::factory()->create();
    $creator = Creator::factory()->create();
    supportFor($fan, $creator);
    Post::factory()->for($creator)->published()->count(21)->create();

    $this->actingAs($fan)->get(route('feed', ['tab' => 'supporting']))
        ->assertInertia(fn ($page) => $page->where('posts.next_page_url', fn (string $url) => str_contains($url, 'tab=supporting')));
});

it('lists the supported creators first, then followed ones, without closed creators', function () {
    $fan = User::factory()->create();
    $supported = Creator::factory()->create();
    Support::factory()->for($fan)->forPlan(Plan::factory()->for($supported)->create())->active()->create();
    $followed = Creator::factory()->create();
    $followed->follow($fan);
    $both = Creator::factory()->create();
    Support::factory()->for($fan)->forPlan(Plan::factory()->for($both)->create())->active()->create();
    $both->follow($fan);
    $closed = Creator::factory()->closed()->create();
    $closed->follow($fan);

    $this->actingAs($fan)->get(route('feed'))
        ->assertInertia(fn ($page) => $page
            ->has('creators', 3)
            ->where('creators.0.supporting', true)
            ->where('creators.1.supporting', true)
            ->where('creators.2.slug', $followed->slug)
            ->where('creators.2.supporting', false));
});
