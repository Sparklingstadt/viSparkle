<?php

use App\Models\Creator;
use App\Services\Billing\AccountSync;

it('enables a creator when stripe reports payouts enabled', function () {
    $gateway = fakeBilling();
    $creator = Creator::factory()->create();
    $accountId = $gateway->createConnectAccount($creator);
    $creator->forceFill(['stripe_account_id' => $accountId])->save();
    $gateway->setAccount($accountId, payoutsEnabled: true, detailsSubmitted: true);

    $synced = app(AccountSync::class)->sync($accountId);

    expect($synced->id)->toBe($creator->id)
        ->and($creator->fresh()->payouts_enabled)->toBeTrue()
        ->and($creator->fresh()->details_submitted)->toBeTrue()
        ->and($creator->fresh()->canAcceptSupports())->toBeTrue();
});

it('disables a creator when stripe stops payouts', function () {
    $gateway = fakeBilling();
    $creator = Creator::factory()->create();
    $accountId = $gateway->createConnectAccount($creator);
    $creator->forceFill(['stripe_account_id' => $accountId, 'payouts_enabled' => true, 'details_submitted' => true])->save();
    $gateway->setAccount($accountId, payoutsEnabled: false, detailsSubmitted: true);

    app(AccountSync::class)->sync($accountId);

    expect($creator->fresh()->payouts_enabled)->toBeFalse()
        ->and($creator->fresh()->details_submitted)->toBeTrue();
});

it('returns null for an account no creator owns', function () {
    $gateway = fakeBilling();
    $accountId = $gateway->createConnectAccount(Creator::factory()->create());

    expect(app(AccountSync::class)->sync($accountId))->toBeNull();
});

it('disables payouts when the account is deauthorized', function () {
    fakeBilling();
    $creator = Creator::factory()->payable()->create();

    app(AccountSync::class)->deauthorize((string) $creator->stripe_account_id);

    expect($creator->fresh()->payouts_enabled)->toBeFalse();
});
