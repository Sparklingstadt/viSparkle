<?php

use App\Models\Creator;
use Database\Seeders\DemoCreatorsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    // Seed as production does: no payout accounts.
    config(['fanbox.billing.driver' => 'stripe']);
    $this->seed(DemoCreatorsSeeder::class);
    $this->gateway = fakeBilling();
});

it('opens a payout account for the eight demo creators and prints their onboarding links', function () {
    $this->artisan('fanbox:demo-payout-accounts')
        ->expectsOutputToContain('sakura-illust: 登録手続きが必要です')
        ->expectsOutputToContain('支援可 0/8')
        ->assertExitCode(0);

    expect(Creator::whereNotNull('stripe_account_id')->pluck('slug')->sort()->values()->all())
        ->toBe(['aoi-music', 'moko-craft', 'night-novel', 'pixel-forge', 'sakura-illust', 'studio-lumen', 'tanaka-photo', 'yuki-cooking']);
});

it('reports the creators whose onboarding is finished', function () {
    // The fake gateway finishes onboarding as soon as a link is made.
    $this->artisan('fanbox:demo-payout-accounts')->assertExitCode(0);
    $accounts = Creator::whereNotNull('stripe_account_id')->pluck('stripe_account_id', 'slug');

    $this->artisan('fanbox:demo-payout-accounts')
        ->expectsOutputToContain('sakura-illust: 支援可')
        ->expectsOutputToContain('支援可 8/8')
        ->assertExitCode(0);

    expect(Creator::whereNotNull('stripe_account_id')->pluck('stripe_account_id', 'slug'))->toEqual($accounts)
        ->and(Creator::where('slug', 'sakura-illust')->sole()->canAcceptSupports())->toBeTrue()
        ->and(Creator::where('slug', 'hiro-3d')->sole()->stripe_account_id)->toBeNull();
});

it('refuses to run with live Stripe keys', function () {
    config(['fanbox.billing.stripe.secret' => 'sk_live_123']);

    $this->artisan('fanbox:demo-payout-accounts')->assertExitCode(1);

    expect(Creator::whereNotNull('stripe_account_id')->count())->toBe(0);
});
