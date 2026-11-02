<?php

use App\Models\Creator;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->creator = Creator::factory()->create();
    $this->actingAs($this->creator->user);
});

it('shows the shop settings', function () {
    $this->creator->update(['shipping_fee' => 500, 'shop_notice' => 'お知らせ']);

    $this->get(route('creator.shop.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/shop')
            ->where('shop.shipping_fee', 500)
            ->where('shop.shop_notice', 'お知らせ'));
});

it('saves shop settings', function () {
    $this->put(route('creator.shop.update'), [
        'shipping_fee' => 600,
        'shop_notice' => '発送は週末にまとめて行います。',
        'shop_contact_email' => 'shop@example.com',
    ])->assertSessionHasNoErrors();

    expect($this->creator->fresh())
        ->shipping_fee->toBe(600)
        ->shop_notice->toBe('発送は週末にまとめて行います。')
        ->shop_contact_email->toBe('shop@example.com');
});

it('allows empty optional fields', function () {
    $this->creator->update(['shop_notice' => 'x', 'shop_contact_email' => 'a@example.com']);

    $this->put(route('creator.shop.update'), ['shipping_fee' => 0, 'shop_notice' => '', 'shop_contact_email' => ''])
        ->assertSessionHasNoErrors();

    expect($this->creator->fresh())->shipping_fee->toBe(0)->shop_notice->toBeNull()->shop_contact_email->toBeNull();
});

it('validates shop settings', function (array $overrides, string $field) {
    $this->put(route('creator.shop.update'), ['shipping_fee' => 600, ...$overrides])->assertSessionHasErrors($field);
})->with([
    'fee high' => [['shipping_fee' => 100001], 'shipping_fee'],
    'fee negative' => [['shipping_fee' => -1], 'shipping_fee'],
    'fee missing' => [['shipping_fee' => null], 'shipping_fee'],
    'notice long' => [['shop_notice' => str_repeat('あ', 2001)], 'shop_notice'],
    'email invalid' => [['shop_contact_email' => 'nope'], 'shop_contact_email'],
]);
