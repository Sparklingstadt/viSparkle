<?php

use App\Models\Creator;
use App\Models\Payment;
use App\Models\User;

it('reports admin state', function () {
    expect(User::factory()->create()->isAdmin())->toBeFalse()
        ->and(User::factory()->admin()->create()->isAdmin())->toBeTrue();
});

it('reports closed creators', function () {
    expect(Creator::factory()->create()->isClosed())->toBeFalse()
        ->and(Creator::factory()->closed()->create()->isClosed())->toBeTrue();
});

it('excludes closed creators from the open scope', function () {
    $open = Creator::factory()->create();
    Creator::factory()->closed()->create();

    expect(Creator::open()->pluck('id')->all())->toBe([$open->id]);
});

it('computes the net amount after refunds', function () {
    $payment = Payment::factory()->create(['amount' => 1000]);
    expect($payment->netAmount())->toBe(1000);

    $payment->forceFill(['refunded_amount' => 400])->save();
    expect($payment->fresh()->netAmount())->toBe(600);
});
