<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Creator;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'creator_id' => Creator::factory(),
            'title' => fake()->sentence(4),
            'body' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => fake()->realText(100)]]],
                ],
            ],
            'excerpt' => fake()->realText(60),
            'required_price' => 0,
            'status' => PostStatus::Draft,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => PostStatus::Published, 'published_at' => now()]);
    }

    public function hidden(string $reason = '規約違反'): static
    {
        return $this->state(fn () => ['hidden_at' => now(), 'hidden_reason' => $reason]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => PostStatus::Draft, 'published_at' => null]);
    }

    public function requiring(int $price): static
    {
        return $this->state(fn () => ['required_price' => $price]);
    }
}
