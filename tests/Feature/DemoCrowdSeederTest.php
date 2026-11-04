<?php

use App\Models\Creator;
use App\Models\User;
use Database\Seeders\DemoCrowdSeeder;
use Database\Seeders\DemoPostLikesSeeder;
use Illuminate\Support\Facades\Storage;

it('tops the service up to fifty creators with a plan, posts and images', function () {
    Storage::fake('public');
    fakeBilling();
    Creator::factory()->count(11)->create();

    $this->seed(DemoCrowdSeeder::class);

    $crowd = Creator::has('plans')->with(['plans', 'posts'])->get();

    expect(Creator::query()->count())->toBe(50)
        ->and($crowd)->toHaveCount(39);

    foreach ($crowd as $creator) {
        expect($creator->plans)->toHaveCount(1)
            ->and($creator->posts->pluck('required_price')->sort()->values()->all())->toBe([0, 500])
            ->and($creator->avatar_path)->not->toBeNull()
            ->and($creator->canAcceptSupports())->toBeTrue();
    }
});

it('adds no creators past fifty', function () {
    Storage::fake('public');
    fakeBilling();
    Creator::factory()->count(11)->create();

    $this->seed(DemoCrowdSeeder::class);
    $this->seed(DemoCrowdSeeder::class);

    expect(Creator::query()->count())->toBe(50);
});

it('makes a hundred fans counting fan@example.com, once', function () {
    DemoPostLikesSeeder::fans();

    expect(User::query()->where('email', 'like', 'demo-fan-%')->count())->toBe(100);
});

it('counts fan@example.com among the hundred fans', function () {
    User::factory()->create(['email' => 'fan@example.com']);

    DemoPostLikesSeeder::fans();
    DemoPostLikesSeeder::fans();

    expect(User::query()->where('email', 'like', 'demo-fan-%')->count())->toBe(99);
});
