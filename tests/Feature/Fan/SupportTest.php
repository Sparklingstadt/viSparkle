<?php

use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Entitlement;

beforeEach(function () {
    $this->creator = Creator::factory()->create();
    $this->low = Plan::factory()->for($this->creator)->price(300)->create();
    $this->fan = User::factory()->create();
});

// Starting a support goes through Checkout; see CheckoutTest.

it('ends a support and relocks posts', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(300)->create();
    $support = Support::factory()->for($this->fan)->forPlan($this->low)->active()->create();

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))->assertRedirect();

    expect($support->fresh()->status)->toBe(SupportStatus::Ended)
        ->and(Entitlement::for($this->fan)->canView($post))->toBeFalse();
});

it('404s ending someone elses support', function () {
    $support = Support::factory()->for(User::factory()->create())->forPlan($this->low)->active()->create();

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))->assertNotFound();
    expect($support->fresh()->status)->toBe(SupportStatus::Active);
});

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
