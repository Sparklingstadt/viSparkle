<?php

use App\Models\Creator;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the registration page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('creator.register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('creator/register'));
});

it('registers a creator', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('creator.register'), ['slug' => 'alice-art', 'display_name' => 'Alice'])
        ->assertRedirect(route('creator.profile.edit'));

    expect($user->fresh()->creator->slug)->toBe('alice-art')
        ->and($user->fresh()->creator->display_name)->toBe('Alice');
});

it('rejects invalid slugs', function (string $slug) {
    $this->actingAs(User::factory()->create())
        ->post(route('creator.register'), ['slug' => $slug, 'display_name' => 'A'])
        ->assertSessionHasErrors('slug');

    expect(Creator::count())->toBe(0);
})->with([
    'uppercase' => 'Alice',
    'leading hyphen' => '-alice',
    'trailing hyphen' => 'alice-',
    'too short' => 'ab',
    'reserved admin' => 'admin',
    'reserved feed' => 'feed',
    'space' => 'al ice',
    'too long' => str_repeat('a', 33),
    'unicode' => 'ありす',
]);

it('rejects duplicate slugs', function () {
    Creator::factory()->create(['slug' => 'alice']);

    $this->actingAs(User::factory()->create())
        ->post(route('creator.register'), ['slug' => 'alice', 'display_name' => 'A'])
        ->assertSessionHasErrors('slug');
});

it('requires a display name of at most 50 characters', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('creator.register'), ['slug' => 'alice', 'display_name' => str_repeat('あ', 51)])
        ->assertSessionHasErrors('display_name');
});

it('redirects non-creators away from the creator area', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('creator.profile.edit'))
        ->assertRedirect(route('creator.register'));
});

it('redirects registered creators away from registration', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)->get(route('creator.register'))->assertRedirect(route('creator.profile.edit'));
    $this->actingAs($creator->user)
        ->post(route('creator.register'), ['slug' => 'another', 'display_name' => 'B'])
        ->assertRedirect(route('creator.profile.edit'));

    expect(Creator::count())->toBe(1);
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('creator.register'))
        ->assertRedirect(route('verification.notice'));
});

it('requires login', function () {
    $this->get(route('creator.register'))->assertRedirect(route('login'));
});

it('shares the creator summary with every page', function () {
    $creator = Creator::factory()->create(['slug' => 'alice', 'display_name' => 'Alice']);

    $this->actingAs($creator->user)
        ->get(route('creator.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.creator.slug', 'alice')
            ->where('auth.creator.display_name', 'Alice')
            ->where('auth.creator.avatar_url', null));
});
