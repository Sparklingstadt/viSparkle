<?php

use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->low = Plan::factory()->price(300)->create();
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
            ->where('supports.0.plan_name', $this->low->name)
            ->where('supports.0.price', 300));
});

it('a fan cannot hold two active supports', function () {
    $fan = User::factory()->create();
    Support::factory()->for($fan)->forPlan(Plan::factory()->price(700)->create())->active()->create();

    expect(fn () => Support::factory()->for($fan)->forPlan(Plan::factory()->price(900)->create())->active()->create())
        ->toThrow(QueryException::class);
});
