<?php

use App\Models\Creator;
use App\Models\User;
use Database\Seeders\DemoCrowdSeeder;
use Database\Seeders\DemoPostLikesSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds 39 creators with a plan, posts and images', function () {
    Storage::fake('public');
    fakeBilling();

    $this->seed(DemoCrowdSeeder::class);

    $creators = Creator::with(['plans', 'posts'])->get();

    expect($creators)->toHaveCount(39);

    foreach ($creators as $creator) {
        expect($creator->plans)->toHaveCount(1)
            ->and($creator->posts->pluck('required_price')->sort()->values()->all())->toBe([0, 500])
            ->and($creator->avatar_path)->not->toBeNull()
            ->and($creator->canAcceptSupports())->toBeTrue();
    }
});

it('adds no creators when seeded twice', function () {
    Storage::fake('public');
    fakeBilling();

    $this->seed(DemoCrowdSeeder::class);
    $this->seed(DemoCrowdSeeder::class);

    expect(Creator::query()->count())->toBe(39);
});

it('makes 99 throwaway fans once', function () {
    DemoPostLikesSeeder::fans();
    DemoPostLikesSeeder::fans();

    expect(User::query()->where('email', 'like', 'demo-fan-%')->count())->toBe(99);
});
