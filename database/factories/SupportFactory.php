<?php

namespace Database\Factories;

use App\Enums\SupportStatus;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Support>
 */
class SupportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory(),
            'creator_id' => fn (array $attributes) => Plan::findOrFail((int) $attributes['plan_id'])->creator_id,
            'price' => fn (array $attributes) => Plan::findOrFail((int) $attributes['plan_id'])->price,
            'status' => SupportStatus::Active,
            'started_at' => now(),
        ];
    }

    public function forPlan(Plan $plan): static
    {
        return $this->state(fn () => [
            'plan_id' => $plan->id,
            'creator_id' => $plan->creator_id,
            'price' => $plan->price,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => SupportStatus::Active, 'ended_at' => null]);
    }

    public function ended(): static
    {
        return $this->state(fn () => ['status' => SupportStatus::Ended, 'ended_at' => now()]);
    }
}
