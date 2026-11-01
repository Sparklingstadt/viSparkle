<?php

use App\Models\User;

it('grants admin rights', function () {
    $user = User::factory()->create(['email' => 'a@example.com']);

    $this->artisan('fanbox:admin', ['email' => 'a@example.com'])
        ->expectsOutputToContain('a@example.com を管理者にしました。')
        ->assertExitCode(0);

    expect($user->fresh()->isAdmin())->toBeTrue();
});

it('revokes admin rights', function () {
    $user = User::factory()->admin()->create(['email' => 'a@example.com']);

    $this->artisan('fanbox:admin', ['email' => 'a@example.com', '--revoke' => true])
        ->expectsOutputToContain('a@example.com の管理者権限を外しました。')
        ->assertExitCode(0);

    expect($user->fresh()->isAdmin())->toBeFalse();
});

it('fails for an unknown email', function () {
    $this->artisan('fanbox:admin', ['email' => 'nobody@example.com'])->assertExitCode(1);
});
