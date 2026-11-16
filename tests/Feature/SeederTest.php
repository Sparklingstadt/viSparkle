<?php

use App\Models\Plan;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => fakePrivateDisk());

it('seeds the creator, a fan, plans and posts', function () {
    $this->seed();

    $creator = User::where('email', 'creator@example.com')->sole();
    $fan = User::where('email', 'fan@example.com')->sole();

    expect($creator->isCreator())->toBeTrue()
        ->and($fan->isCreator())->toBeFalse()
        ->and($fan->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $creator->password))->toBeTrue()
        ->and(Plan::orderBy('price')->pluck('price')->all())->toBe([300, 500, 1000])
        ->and(Post::published()->pluck('required_price')->sort()->values()->all())->toBe([0, 300, 1000]);
});
