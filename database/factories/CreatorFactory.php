<?php

namespace Database\Factories;

use App\Models\Creator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
}
