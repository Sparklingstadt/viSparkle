<?php

use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('rejects a second active support for the same user and creator', function () {
    $creator = Creator::factory()->create();
    $user = User::factory()->create();
    $plan = Plan::factory()->for($creator)->create();
    $other = Plan::factory()->for($creator)->create();

    Support::factory()->forPlan($plan)->create(['user_id' => $user->id]);

    expect(fn () => Support::factory()->forPlan($other)->create(['user_id' => $user->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows many ended supports and one active', function () {
    $plan = Plan::factory()->create();
    $user = User::factory()->create();

    Support::factory()->forPlan($plan)->ended()->count(2)->create(['user_id' => $user->id]);
    Support::factory()->forPlan($plan)->create(['user_id' => $user->id]);

    expect(Support::count())->toBe(3);
});

it('frees the slot when a support ends', function () {
    $plan = Plan::factory()->create();
    $user = User::factory()->create();

    $support = Support::factory()->forPlan($plan)->create(['user_id' => $user->id]);
    $support->update(['status' => SupportStatus::Ended]);

    Support::factory()->forPlan($plan)->create(['user_id' => $user->id]);

    expect(Support::active()->count())->toBe(1);
});

it('reports whether a creator can accept supports', function () {
    expect(Creator::factory()->create()->canAcceptSupports())->toBeFalse()
        ->and(Creator::factory()->payable()->create()->canAcceptSupports())->toBeTrue()
        ->and(Creator::factory()->payable()->create(['payouts_enabled' => false])->canAcceptSupports())->toBeFalse();
});

it('reports whether a support is billed through Stripe', function () {
    expect(Support::factory()->create()->isBilling())->toBeFalse()
        ->and(Support::factory()->billing()->create()->isBilling())->toBeTrue();
});

it('still cascades supports when the user is deleted', function () {
    $support = Support::factory()->create();

    $support->user->delete();

    expect(Support::count())->toBe(0);
});
