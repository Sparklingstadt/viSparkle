<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

function supportableCreator(): Creator
{
    return Creator::factory()->payable()->has(Plan::factory())->create();
}

it('lists only creators a fan can support, newest first', function () {
    $older = supportableCreator();
    $newer = supportableCreator();

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('creators', 2)
            ->where('creators.0.slug', $newer->slug)
            ->where('creators.1.slug', $older->slug));
});

it('hides creators without a ready payout account or an accepting plan', function () {
    Creator::factory()->has(Plan::factory())->create();
    Creator::factory()->payable()->has(Plan::factory())->create(['payouts_enabled' => false]);
    Creator::factory()->payable()->create();
    Creator::factory()->payable()->has(Plan::factory()->notAccepting())->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('creators', 0));
});

it('hides closed creators and suspended owners even when supportable', function () {
    Creator::factory()->closed()->payable()->has(Plan::factory())->create();
    Creator::factory()->for(User::factory()->suspended())->payable()->has(Plan::factory())->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('creators', 0));
});

it('shows at most 12 creators', function () {
    collect(range(1, 13))->each(fn () => supportableCreator());

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('creators', 12));
});
