<?php

namespace Database\Factories;

use App\Models\Creator;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'creator_id' => Creator::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->realText(120),
            'price' => 1500,
            'stock' => 5,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function soldOut(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
