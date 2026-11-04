<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The whole demo: fifty creators with their goods, a hundred fans, likes and a filled weekly ranking. Uses no model
 * factories, so it also runs on production, where nobody knows the accounts' passwords and no real payment is involved:
 *   php artisan db:seed --class=DemoShowcaseSeeder --force
 *
 * Only for a service with no real users. Safe to re-run: what exists already is left alone.
 */
class DemoShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoCreatorsSeeder::class,
            DemoCrowdSeeder::class,
            DemoPostLikesSeeder::class,
            DemoSupportsSeeder::class,
        ]);
    }
}
