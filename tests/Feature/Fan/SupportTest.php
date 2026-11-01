<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;

beforeEach(function () {
    $this->creator = Creator::factory()->create();
    $this->low = Plan::factory()->for($this->creator)->price(300)->create();
    $this->fan = User::factory()->create();
});

// Starting a support goes through Checkout; see CheckoutTest.

it('lists active supports on the supporting page', function () {
    Support::factory()->for($this->fan)->forPlan($this->low)->active()->create();
    Support::factory()->for($this->fan)->ended()->create();

    $this->actingAs($this->fan)->get(route('supporting.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fan/supporting')
            ->has('supports', 1)
            ->where('supports.0.creator.slug', $this->creator->slug)
            ->where('supports.0.plan_name', $this->low->name)
            ->where('supports.0.price', 300));
});
