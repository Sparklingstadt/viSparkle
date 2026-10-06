<?php

namespace Database\Factories;

use App\Models\Creator;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'creator_id' => Creator::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->realText(80),
            'price' => fake()->unique()->numberBetween(1, 500) * 100,
            'is_accepting' => true,
            'sort' => 0,
        ];
    }

    public function price(int $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function notAccepting(): static
    {
        return $this->state(fn () => ['is_accepting' => false]);
    }
}
