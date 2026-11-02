<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('lists listed products newest first with their creator', function () {
    $older = Product::factory()->create();
    $newer = Product::factory()->soldOut()->create();

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products', 2)
            ->where('products.0.id', $newer->id)
            ->where('products.0.sold_out', true)
            ->where('products.0.creator.slug', $newer->creator->slug)
            ->where('products.1.id', $older->id)
            ->where('products.1.sold_out', false));
});

it('hides unpublished, hidden and deleted products', function () {
    Product::factory()->unpublished()->create();
    Product::factory()->create(['hidden_at' => now(), 'hidden_reason' => 'x']);
    Product::factory()->create()->delete();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('products', 0));
});

it('hides products of closed creators and suspended owners', function () {
    Product::factory()->for(Creator::factory()->closed())->create();
    Product::factory()->for(Creator::factory()->for(User::factory()->suspended()))->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('products', 0));
});

it('shows at most 12 products', function () {
    Product::factory()->count(13)->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('products', 12));
});
