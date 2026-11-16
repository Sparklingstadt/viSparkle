<?php

use App\Models\User;

it('makes a user the creator', function () {
    $user = User::factory()->create(['email' => 'fan@example.com']);

    $this->artisan('visparkle:creator', ['email' => 'fan@example.com'])->assertSuccessful();

    expect($user->fresh()->isCreator())->toBeTrue();
});

it('takes the creator role back with --revoke', function () {
    $user = User::factory()->creator()->create(['email' => 'creator@example.com']);

    $this->artisan('visparkle:creator', ['email' => 'creator@example.com', '--revoke' => true])->assertSuccessful();

    expect($user->fresh()->isCreator())->toBeFalse();
});

it('fails for an unknown email', function () {
    $this->artisan('visparkle:creator', ['email' => 'nobody@example.com'])->assertExitCode(1);
});
