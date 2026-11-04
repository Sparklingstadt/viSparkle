<?php

namespace Database\Seeders;

use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fills the home page's weekly ranking by having the throwaway fans start supporting up to 25 creators within the last
 * week. These supports have no Stripe subscription, like a free grant: nothing is charged and nothing syncs with them.
 * The ranking only counts seven days, so it empties again a week after seeding.
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

                $fans->random(random_int(1, self::MAX_SUPPORTERS))->each(fn (User $fan) => Support::create([
                    'user_id' => $fan->id,
                    'creator_id' => $creator->id,
                    'plan_id' => $plan->id,
                    'price' => $plan->price,
                    'status' => SupportStatus::Active,
                    'started_at' => now()->subDays(random_int(0, 6)),
                ]));
            });
    }
}
