<?php

use App\Models\Creator;
use Database\Seeders\DemoCreatorsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    // The demo shop writes its digital work's file to the private disk.
    fakePrivateDisk();
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

it('warns about demo creators that have not been seeded', function () {
    Creator::where('slug', 'aoi-music')->sole()->user->delete();

    $this->artisan('fanbox:demo-payout-accounts')
        ->expectsOutputToContain('aoi-music: クリエイターが見つかりません')
        ->expectsOutputToContain('支援可 0/8')
        ->assertExitCode(0);

    expect(Creator::whereNotNull('stripe_account_id')->count())->toBe(7);
});

it('stops when the payment service fails and keeps the accounts opened so far', function () {
    $first = Creator::whereIn('slug', DemoCreatorsSeeder::acceptingSupports())->orderBy('id')->first();
    $broken = Creator::whereIn('slug', DemoCreatorsSeeder::acceptingSupports())->orderBy('id')->skip(1)->first();
    $broken->forceFill(['stripe_account_id' => 'acct_missing'])->save();

    $this->artisan('fanbox:demo-payout-accounts')
        ->expectsOutputToContain('決済サービスに接続できませんでした')
        ->doesntExpectOutputToContain('支援可 ')
        ->assertExitCode(1);

    expect($first->fresh()->stripe_account_id)->not->toBeNull()
        ->and(Creator::whereNotNull('stripe_account_id')->count())->toBe(2);
});

it('detaches the payout accounts, even with live Stripe keys', function () {
    $this->artisan('fanbox:demo-payout-accounts')->assertExitCode(0);
    $this->artisan('fanbox:demo-payout-accounts')->assertExitCode(0);
    $sakuraAccount = Creator::where('slug', 'sakura-illust')->sole()->stripe_account_id;
    config(['fanbox.billing.stripe.secret' => 'sk_live_123']);

    $this->artisan('fanbox:demo-payout-accounts', ['--detach' => true])
        ->expectsOutputToContain("sakura-illust: {$sakuraAccount}")
        ->expectsOutputToContain('入金口座を外しました（8人）')
        ->assertExitCode(0);

    $sakura = Creator::where('slug', 'sakura-illust')->sole();
    expect(Creator::whereNotNull('stripe_account_id')->count())->toBe(0)
        ->and($sakura->payouts_enabled)->toBeFalse()
        ->and($sakura->canAcceptSupports())->toBeFalse();
});
