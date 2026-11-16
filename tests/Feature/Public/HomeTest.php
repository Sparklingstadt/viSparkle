<?php

use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('home shows the creator profile from config', function () {
    config(['visparkle.creator.name' => 'テスト', 'visparkle.creator.bio' => '紹介文です。']);

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('public/home')
        ->where('creator.name', 'テスト')
        ->where('creator.bio', '紹介文です。'));
});

it('home works when no creator account exists', function () {
    expect(User::count())->toBe(0);

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('plans', 0)
        ->has('posts.data', 0));
});

it('home lists only accepting plans and published posts', function () {
    $accepting = Plan::factory()->price(500)->create();
    Plan::factory()->price(1000)->notAccepting()->create();
    $published = Post::factory()->published()->create();
    Post::factory()->draft()->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('plans', 1)
        ->where('plans.0.id', $accepting->id)
        ->has('posts.data', 1)
        ->where('posts.data.0.id', $published->id));
});

it('home keeps a supporter\'s current plan visible even when it stopped accepting', function () {
    $plan = Plan::factory()->price(1000)->notAccepting()->create();
    $fan = User::factory()->create();
    Support::factory()->for($fan)->forPlan($plan)->active()->create();

    $this->actingAs($fan)->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('plans', 1)
        ->where('viewerSupportPrice', 1000)
        ->where('viewerPlanId', $plan->id));
});

it('home shows every plan to the creator', function () {
    Plan::factory()->price(500)->create();
    Plan::factory()->price(1000)->notAccepting()->create();

    $this->actingAs(User::factory()->creator()->create())->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('plans', 2)
        ->where('isCreator', true));
});
