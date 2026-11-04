<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePrivateDisk();
    $this->fan = User::factory()->create();
});

it('lists the products the fan liked, latest like first', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();
    Product::factory()->create();
    Product::factory()->create()->like(User::factory()->create());

    Carbon::setTestNow(now()->subHour());
    $second->like($this->fan);
    Carbon::setTestNow();
    $first->like($this->fan);

    $this->actingAs($this->fan)->get(route('liked-products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fan/liked-products')
            ->has('products.data', 2)
            ->where('products.data.0.id', $first->id)
            ->where('products.data.0.liked', true)
            ->where('products.data.0.like_count', 1)
            ->has('products.data.0.creator')
            ->where('products.data.1.id', $second->id));
});

it('leaves out products that are not on sale, and shows them again once they are', function () {
    $unpublished = Product::factory()->create();
    $hidden = Product::factory()->create();
    $deleted = Product::factory()->create();
    $closed = Product::factory()->for(Creator::factory()->closed())->create();
    $suspended = Product::factory()->for(Creator::factory()->for(User::factory()->suspended()))->create();

    foreach ([$unpublished, $hidden, $deleted, $closed, $suspended] as $product) {
        $product->like($this->fan);
    }

    $unpublished->update(['is_published' => false]);
    $hidden->forceFill(['hidden_at' => now()])->save();
    $deleted->delete();

    $this->actingAs($this->fan)->get(route('liked-products.index'))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 0));

    $unpublished->update(['is_published' => true]);

    $this->actingAs($this->fan)->get(route('liked-products.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $unpublished->id));
});

it('pages through twenty at a time', function () {
    Product::factory()->count(21)->create()->each->like($this->fan);

    $this->actingAs($this->fan)->get(route('liked-products.index'))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 20));
});

it('needs an account', function () {
    $this->get(route('liked-products.index'))->assertRedirect(route('login'));
});
