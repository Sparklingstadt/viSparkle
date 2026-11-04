<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use Database\Seeders\DemoSupportsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('fills the weekly ranking with ten creators', function () {
    Creator::factory()->count(11)->create()->each(fn (Creator $creator) => Plan::factory()->for($creator)->create());

    $this->seed(DemoSupportsSeeder::class);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->has('popularCreators', 10));
});

it('changes nothing when seeded twice', function () {
    Plan::factory()->for(Creator::factory())->create();

    $this->seed(DemoSupportsSeeder::class);
    $supports = Support::query()->count();
    $this->seed(DemoSupportsSeeder::class);

    expect($supports)->toBeGreaterThan(0)
        ->and(Support::query()->count())->toBe($supports);
});
