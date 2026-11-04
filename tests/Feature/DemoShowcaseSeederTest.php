<?php

use App\Models\Creator;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use Database\Seeders\DemoShowcaseSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    fakePrivateDisk();
});

it('builds the whole demo on production without a known password or payment', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--class' => DemoShowcaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(Creator::query()->count())->toBe(50)
        ->and(User::query()->doesntHave('creator')->count())->toBe(100)
        ->and(Post::published()->where('required_price', 0)->where('like_count', 0)->count())->toBe(0)
        ->and(Support::query()->whereNotNull('stripe_subscription_id')->count())->toBe(0)
        ->and(Creator::query()->whereNotNull('stripe_account_id')->count())->toBe(0);

    User::query()->each(fn (User $user) => expect(Hash::check('password', $user->password))->toBeFalse());

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('popularCreators', 25));
});

it('changes nothing when run twice', function () {
    fakeBilling();

    $this->seed(DemoShowcaseSeeder::class);
    $counts = [User::query()->count(), Creator::query()->count(), Support::query()->count()];
    $this->seed(DemoShowcaseSeeder::class);

    expect([User::query()->count(), Creator::query()->count(), Support::query()->count()])->toBe($counts);
});
