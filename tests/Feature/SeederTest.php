<?php

use App\Models\Creator;
use App\Models\User;
use Database\Seeders\DemoCreatorsSeeder;
use Illuminate\Support\Facades\Storage;

it('seeds demo accounts, plans and posts', function () {
    $gateway = fakeBilling();
    $this->seed();

    $creator = Creator::where('slug', 'demo-creator')->sole();

    expect(User::where('email', 'fan@example.com')->sole()->email_verified_at)->not->toBeNull()
        ->and($creator->user->email)->toBe('creator@example.com')
        ->and($creator->plans()->pluck('price')->all())->toBe([300, 500, 1000])
        ->and($creator->canAcceptSupports())->toBeTrue()
        ->and($gateway->retrieveAccount('acct_fake_demo')->payoutsEnabled)->toBeTrue()
        ->and($creator->posts()->published()->pluck('required_price')->sort()->values()->all())->toBe([0, 300, 1000]);
});

it('seeds ten more demo creators with 1-3 plans and 2-5 posts each', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    $others = Creator::where('slug', '!=', 'demo-creator')->with(['plans', 'posts'])->get();

    expect($others)->toHaveCount(10);

    foreach ($others as $creator) {
        $prices = $creator->plans->pluck('price');

        expect($prices->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            ->and($prices->unique()->count())->toBe($prices->count())
            ->and($creator->posts->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5)
            ->and($creator->posts->every(fn ($post) => $post->isPublished()))->toBeTrue()
            // Every locked post must be unlockable by one of the creator's plans.
            ->and($creator->posts->pluck('required_price')->diff([0, ...$prices])->all())->toBe([]);
    }
});

it('gives every demo creator an avatar and a header image', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    foreach (Creator::all() as $creator) {
        expect($creator->avatar_path)->not->toBeNull()
            ->and($creator->header_path)->not->toBeNull();

        Storage::disk('public')->assertExists([$creator->avatar_path, $creator->header_path]);

        expect(getimagesizefromstring(Storage::disk('public')->get($creator->avatar_path))[0])->toBe(400)
            ->and(getimagesizefromstring(Storage::disk('public')->get($creator->header_path))[0])->toBe(1500);
    }
});

it('can be re-run without duplicating creators or replacing images', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();
    $avatar = Creator::where('slug', 'sakura-illust')->sole()->avatar_path;

    $this->seed(DemoCreatorsSeeder::class);

    expect(Creator::count())->toBe(11)
        ->and(Creator::where('slug', 'sakura-illust')->sole()->avatar_path)->toBe($avatar);
});
