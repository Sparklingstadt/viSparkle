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
