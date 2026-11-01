<?php

use App\Models\Creator;
use App\Models\User;

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
