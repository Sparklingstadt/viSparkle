<?php

namespace Database\Seeders;

use App\Models\Creator;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data: fills the home page's weekly ranking by having the throwaway fans start supporting up to 25 creators
 * within the last week. The ranking only counts seven days, so it empties again a week after seeding.
 * Creators the fans support already count towards the 25, so seeding twice changes nothing.
 */
class DemoSupportsSeeder extends Seeder
{
    private const RANKED_CREATORS = 25;

    private const MAX_SUPPORTERS = 10;

    public function run(): void
    {
        $fans = DemoPostLikesSeeder::fans();
        $supportedByFans = fn ($query) => $query->whereIn('user_id', $fans->modelKeys());
        $ranked = Creator::whereHas('supports', $supportedByFans)->count();

        Creator::open()
            ->has('plans')
            ->whereDoesntHave('supports', $supportedByFans)
            ->inRandomOrder()
            ->limit(max(0, self::RANKED_CREATORS - $ranked))
            ->get()
            ->each(function (Creator $creator) use ($fans) {
                $plan = $creator->plans()->orderBy('price')->firstOrFail();

                $fans->random(random_int(1, self::MAX_SUPPORTERS))->each(fn (User $fan) => Support::factory()
                    ->for($fan)
                    ->forPlan($plan)
                    ->active()
                    ->create(['started_at' => now()->subDays(random_int(0, 6))]));
            });
    }
}
