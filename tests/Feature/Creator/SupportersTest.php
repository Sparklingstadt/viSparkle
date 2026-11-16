<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;

it('lists active supporters and counts per plan', function () {
    $creator = Creator::factory()->create();
    $plan = Plan::factory()->for($creator)->price(500)->create(['name' => 'ベーシック']);
    Plan::factory()->for($creator)->price(1000)->create();
    $alice = User::factory()->create(['name' => 'Alice']);
    Support::factory()->for($alice)->forPlan($plan)->active()->create();
    Support::factory()->forPlan($plan)->ended()->create();
    Support::factory()->forPlan(Plan::factory()->create())->active()->create();

    $this->actingAs($creator->user)->get(route('creator.supporters.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('creator/supporters')
            ->has('plans', 2)
            ->where('plans.0.price', 500)
            ->where('plans.0.active_supporters_count', 1)
            ->where('plans.1.active_supporters_count', 0)
            ->has('supporters.data', 1)
            ->where('supporters.data.0.name', 'Alice')
            ->where('supporters.data.0.plan_name', 'ベーシック')
            ->where('supporters.data.0.price', 500));
});
