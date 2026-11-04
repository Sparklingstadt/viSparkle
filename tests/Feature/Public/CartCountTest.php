<?php

use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('shares the number of items in the viewer\'s cart', function () {
    $fan = User::factory()->create();

    foreach ([2, 3] as $quantity) {
        $fan->cartItems()->create(['product_variant_id' => Product::factory()->create()->variants()->firstOrFail()->id, 'quantity' => $quantity]);
    }

    User::factory()->create()->cartItems()->create(['product_variant_id' => Product::factory()->create()->variants()->firstOrFail()->id, 'quantity' => 9]);

    $this->actingAs($fan)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('cartCount', 5));
});

it('shares zero with guests', function () {
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('cartCount', 0));
});
