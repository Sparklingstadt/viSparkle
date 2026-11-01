<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Legal\LegalDocuments;

function inertiaHeaders(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

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

it('does not redirect legal pages for a stale user', function () {
    $user = User::factory()->termsNotAccepted()->create();

    foreach (['legal.tokushoho', 'legal.terms', 'legal.privacy'] as $name) {
        $this->actingAs($user)->get(route($name))->assertOk();
    }
});

it('does not redirect the email verification notice for a stale user', function () {
    $user = User::factory()->unverified()->termsNotAccepted()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    expect($response->headers->get('Location'))->not->toBe(route('legal.accept'));
});

it('does not redirect the fake checkout page for a stale user', function () {
    fakeBilling();
    $user = User::factory()->termsNotAccepted()->create();

    $response = $this->actingAs($user)->get(route('dev.fake-checkout.show', 'cs_missing'));

    $response->assertNotFound();
});

it('does not redirect the checkout return page for a stale user', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $response = $this->actingAs($user)->get(route('supports.checkout.complete'));

    expect($response->headers->get('Location'))->not->toBe(route('legal.accept'));
});

it('redirects an Inertia visit by a stale user', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $this->actingAs($user)->get('/feed', inertiaHeaders())->assertRedirect(route('legal.accept'));
});

it('does not overwrite the intended URL on a prefetch request', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $this->actingAs($user)->get('/settings/profile')->assertRedirect(route('legal.accept'));
    $this->actingAs($user)->get('/feed', [...inertiaHeaders(), 'Purpose' => 'prefetch'])
        ->assertRedirect(route('legal.accept'));

    $this->actingAs($user)->post(route('legal.accept.store'))->assertRedirect('/settings/profile');
});

it('does not block a POST/PATCH request by a stale user', function () {
    $user = User::factory()->termsNotAccepted()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'X', 'email' => $user->email])
        ->assertRedirect(route('profile.edit'));
});

it('does not redirect JSON requests', function () {
    $this->actingAs(User::factory()->termsNotAccepted()->create())
        ->getJson('/feed')
        ->assertOk();
});
