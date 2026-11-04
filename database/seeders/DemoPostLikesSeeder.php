<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data: gives every public post one to ten likes from throwaway fans.
 * Posts that already have likes are left alone, so seeding twice changes nothing.
 */
class DemoPostLikesSeeder extends Seeder
{
    private const MAX_LIKES = 10;

    public function run(): void
    {
        $fans = collect(range(1, self::MAX_LIKES))->map(fn (int $number) => User::query()->where('email', "demo-fan-{$number}@example.com")->first()
            ?? User::factory()->create(['name' => "デモファン{$number}", 'email' => "demo-fan-{$number}@example.com"]));

        Post::published()
            ->visible()
            ->where('required_price', 0)
            ->where('like_count', 0)
            ->get()
            ->each(function (Post $post) use ($fans) {
                $fans->random(random_int(1, self::MAX_LIKES))->each(fn (User $fan) => $post->like($fan));
            });
    }
}
