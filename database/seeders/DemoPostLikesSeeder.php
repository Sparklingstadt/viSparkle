<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

/**
 * Local demo data: gives every public post one to ten likes from throwaway fans.
 * Posts that already have likes are left alone, so seeding twice changes nothing.
 */
class DemoPostLikesSeeder extends Seeder
{
    private const MAX_LIKES = 10;

    /**
     * With fan@example.com, this makes one hundred fans.
     */
    private const FANS = 99;

    /**
     * The throwaway fans, made on first use.
     *
     * @return Collection<int, User>
     */
    public static function fans(): Collection
    {
        return new Collection(array_map(
            fn (int $number) => User::query()->where('email', "demo-fan-{$number}@example.com")->first()
                ?? User::factory()->create(['name' => "デモファン{$number}", 'email' => "demo-fan-{$number}@example.com"]),
            range(1, self::FANS),
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
