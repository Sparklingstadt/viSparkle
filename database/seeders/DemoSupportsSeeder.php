<?php

namespace Database\Seeders;

use App\Models\Creator;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data: fills the home page's weekly ranking by having the throwaway fans start supporting ten creators
 * within the last week. The ranking only counts seven days, so it empties again a week after seeding.
 * Does nothing once the fans support anyone, so seeding twice changes nothing.
 */
class DemoSupportsSeeder extends Seeder
{
    private const RANKED_CREATORS = 10;

    public function run(): void
    {
        $fans = DemoPostLikesSeeder::fans();

        if (Support::query()->whereIn('user_id', $fans->modelKeys())->exists()) {
            return;
        }

        Creator::open()
            ->has('plans')
            ->inRandomOrder()
            ->limit(self::RANKED_CREATORS)
            ->get()
            ->each(function (Creator $creator) use ($fans) {
                $plan = $creator->plans()->orderBy('price')->firstOrFail();

                $fans->random(random_int(1, $fans->count()))->each(fn (User $fan) => Support::factory()
                    ->for($fan)
                    ->forPlan($plan)
                    ->active()
                    ->create(['started_at' => now()->subDays(random_int(0, 6))]));
            });
    }
}
