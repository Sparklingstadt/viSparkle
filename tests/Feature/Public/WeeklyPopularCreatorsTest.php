<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

function supportCreator(Creator $creator, int $count = 1, array $attributes = []): void
{
    $plan = Plan::factory()->for($creator)->create();
    Support::factory()->forPlan($plan)->active()->count($count)->create($attributes);
}

it('ranks creators by fans who started supporting in the last 7 days', function () {
    $one = Creator::factory()->create();
    $three = Creator::factory()->create();
    $two = Creator::factory()->create();
    Creator::factory()->create();
    supportCreator($one);
    supportCreator($three, 3);
    supportCreator($two, 2, ['started_at' => now()->subDays(6)]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('popularCreators', 3)
            ->where('popularCreators.0.slug', $three->slug)
            ->where('popularCreators.1.slug', $two->slug)
            ->where('popularCreators.2.slug', $one->slug));
});

it('ignores old, ended and self supports', function () {
    $creator = Creator::factory()->create();
    supportCreator($creator, 1, ['started_at' => now()->subDays(8)]);
    Support::factory()->forPlan(Plan::factory()->for($creator)->create())->ended()->create();
    Support::factory()->for($creator->user)->forPlan(Plan::factory()->for($creator)->create())->active()->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('popularCreators', 0));
});

it('breaks ties by total active supporters', function () {
    $small = Creator::factory()->create();
    $big = Creator::factory()->create();
    supportCreator($small);
    supportCreator($big);
    supportCreator($big, 2, ['started_at' => now()->subMonth()]);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('popularCreators.0.slug', $big->slug)
            ->where('popularCreators.1.slug', $small->slug));
});

it('hides closed creators and suspended owners', function () {
    $closed = Creator::factory()->closed()->create();
    $suspended = Creator::factory()->for(User::factory()->suspended())->create();
    supportCreator($closed);
    supportCreator($suspended);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('popularCreators', 0));
});

it('shows at most 25 creators', function () {
    Creator::factory()->count(26)->create()->each(fn (Creator $creator) => supportCreator($creator));

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('popularCreators', 25));
});
