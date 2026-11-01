<?php

namespace Database\Factories;

use App\Models\Creator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Creator>
 */
class CreatorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'slug' => fake()->unique()->regexify('[a-z][a-z0-9]{5,10}'),
            'display_name' => fake()->name(),
            'bio' => fake()->realText(120),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['closed_at' => now()]);
    }

    public function payable(): static
    {
        return $this->state(fn () => [
            'stripe_account_id' => 'acct_fake_'.Str::random(8),
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]);
    }
}
