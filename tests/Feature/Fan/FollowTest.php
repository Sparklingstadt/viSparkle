<?php

use App\Models\Creator;
use App\Models\Follow;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = Creator::factory()->create();
    $this->fan = User::factory()->create();
});

it('follows and unfollows idempotently', function () {
    $url = route('creators.follow.store', $this->creator);

    $this->actingAs($this->fan)->post($url)->assertRedirect();
    $this->actingAs($this->fan)->post($url)->assertRedirect();
    expect(Follow::count())->toBe(1);

    $this->actingAs($this->fan)->delete(route('creators.follow.destroy', $this->creator))->assertRedirect();
    $this->actingAs($this->fan)->delete(route('creators.follow.destroy', $this->creator))->assertRedirect();
    expect(Follow::count())->toBe(0);
});

it('sends guests to the login page', function () {
    $this->post(route('creators.follow.store', $this->creator))->assertRedirect(route('login'));

    expect(Follow::count())->toBe(0);
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->post(route('creators.follow.store', $this->creator))
        ->assertRedirect(route('verification.notice'));

    expect(Follow::count())->toBe(0);
});

it('forbids following yourself', function () {
    $this->actingAs($this->creator->user)->post(route('creators.follow.store', $this->creator))->assertForbidden();

    expect(Follow::count())->toBe(0);
});

it('forbids following a closed creator', function () {
    $closed = Creator::factory()->closed()->create();

    $this->actingAs($this->fan)->post(route('creators.follow.store', $closed))->assertForbidden();

    expect(Follow::count())->toBe(0);
});

it('lets a follower unfollow a closed creator', function () {
    $this->creator->follow($this->fan);
    $this->creator->forceFill(['closed_at' => now()])->save();

    $this->actingAs($this->fan)->delete(route('creators.follow.destroy', $this->creator))->assertRedirect();

    expect(Follow::count())->toBe(0);
});

it('404s a creator whose owner is suspended', function () {
    $suspended = Creator::factory()->for(User::factory()->suspended())->create();

    $this->actingAs($this->fan)->post(route('creators.follow.store', $suspended))->assertNotFound();
    $this->actingAs($this->fan)->delete(route('creators.follow.destroy', $suspended))->assertNotFound();
});

it('tells the creator page whether the viewer follows', function () {
    $this->get(route('creators.show', $this->creator))
        ->assertInertia(fn (Assert $page) => $page->where('viewerFollows', false));
    $this->actingAs($this->fan)->get(route('creators.show', $this->creator))
        ->assertInertia(fn (Assert $page) => $page->where('viewerFollows', false));

    $this->creator->follow($this->fan);

    $this->actingAs($this->fan)->get(route('creators.show', $this->creator))
        ->assertInertia(fn (Assert $page) => $page->where('viewerFollows', true));
});

it('keeps the follower count out of the public page', function () {
    $this->creator->follow($this->fan);

    $this->actingAs($this->creator->user)->get(route('creators.show', $this->creator))
        ->assertInertia(fn (Assert $page) => $page->missing('followerCount')->missing('creator.follower_count'));
});
