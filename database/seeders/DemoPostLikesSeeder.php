<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Gives every public post one to ten likes from throwaway fans. Uses no model factories, so it also runs on production.
 * Posts that already have likes are left alone, so seeding twice changes nothing.
 */
class DemoPostLikesSeeder extends Seeder
{
    private const MAX_LIKES = 10;

    private const TOTAL_FANS = 100;

    /**
     * The throwaway fans, made on first use.
     *
     * @return Collection<int, User>
     */
    public static function fans(): Collection
    {
        // fan@example.com counts as one of the hundred where it exists (it doesn't on production).
        $count = self::TOTAL_FANS - User::query()->where('email', 'fan@example.com')->count();

        return new Collection(array_map(
            fn (int $number) => User::query()->where('email', "demo-fan-{$number}@example.com")->first()
                ?? DemoCreatorsSeeder::account("デモファン{$number}", "demo-fan-{$number}@example.com"),
            range(1, $count),
        ));
    }

    public function run(): void
    {
        $fans = self::fans();

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
