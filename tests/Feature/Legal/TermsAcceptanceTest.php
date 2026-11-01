<?php

use App\Models\User;
use App\Services\Legal\LegalDocuments;

it('redirects a user on an old terms version to the acceptance page', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $this->actingAs($user)->get('/feed')->assertRedirect(route('legal.accept'));
});

it('redirects a user who never accepted the terms', function () {
    $user = User::factory()->create();
    $user->forceFill(['terms_version' => null, 'terms_accepted_at' => null])->save();

    $this->actingAs($user)->get('/feed')->assertRedirect(route('legal.accept'));
});

it('lets a user on the current version through', function () {
    $this->actingAs(User::factory()->create())->get('/feed')->assertOk();
});

it('shows the acceptance page with the current version', function () {
    $this->actingAs(User::factory()->termsNotAccepted()->create())
        ->get(route('legal.accept'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('legal/accept')
            ->where('version', LegalDocuments::termsVersion()));
});

it('records the acceptance and returns to the intended page', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $this->actingAs($user)->get('/settings/profile')->assertRedirect(route('legal.accept'));

    $this->actingAs($user)->post(route('legal.accept.store'))->assertRedirect('/settings/profile');

    $user->refresh();
    expect($user->terms_version)->toBe(LegalDocuments::termsVersion())
        ->and($user->terms_accepted_at)->not->toBeNull();

    $this->actingAs($user)->get('/feed')->assertOk();
});

it('goes to the feed after accepting when there is no intended page', function () {
    $this->actingAs(User::factory()->termsNotAccepted()->create())
        ->post(route('legal.accept.store'))
        ->assertRedirect('/feed');
});

it('does not redirect legal pages, logout or the webhook for a stale user', function () {
    $user = User::factory()->termsNotAccepted()->create();

    foreach (['legal.tokushoho', 'legal.terms', 'legal.privacy'] as $name) {
        $this->actingAs($user)->get(route($name))->assertOk();
    }

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    expect($this->post('/stripe/webhook')->isRedirection())->toBeFalse();
});

it('does not block non-GET requests of a stale user', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'X', 'email' => $user->email])
        ->assertRedirect(route('profile.edit'));
});

it('does not redirect JSON requests', function () {
    $this->actingAs(User::factory()->termsNotAccepted()->create())
        ->getJson('/feed')
        ->assertOk();
});
