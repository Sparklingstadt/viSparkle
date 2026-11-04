<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use App\Services\Billing\AccountDeletion;
use Inertia\Testing\AssertableInertia as Assert;

function productLikeUrl(Product $product): string
{
    return route('shop.products.like.store', [$product->creator, $product]);
}

beforeEach(function () {
    fakePrivateDisk();
    $this->fan = User::factory()->create();
    $this->product = Product::factory()->create();
});

it('likes and unlikes a product, once each', function () {
    $url = productLikeUrl($this->product);

    $this->actingAs($this->fan)->post($url)->assertRedirect();
    $this->actingAs($this->fan)->post($url)->assertRedirect();

    expect($this->product->fresh()->like_count)->toBe(1)
        ->and($this->fan->productLikes()->count())->toBe(1);

    $this->actingAs($this->fan)->delete($url)->assertRedirect();
    $this->actingAs($this->fan)->delete($url)->assertRedirect();

    expect($this->product->fresh()->like_count)->toBe(0)
        ->and($this->fan->productLikes()->count())->toBe(0);
});

it('needs a verified account', function () {
    $this->post(productLikeUrl($this->product))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->post(productLikeUrl($this->product))
        ->assertRedirect(route('verification.notice'));

    expect($this->product->fresh()->like_count)->toBe(0);
});

it('does not let a creator like their own product', function () {
    $this->actingAs($this->product->creator->user)->post(productLikeUrl($this->product))->assertForbidden();

    expect($this->product->fresh()->like_count)->toBe(0);
});

it('treats unlisted products as missing', function () {
    $unpublished = Product::factory()->unpublished()->create();
    $hidden = Product::factory()->create(['hidden_at' => now()]);
    $closed = Product::factory()->for(Creator::factory()->closed())->create();
    $other = Creator::factory()->create();

    $this->actingAs($this->fan)->post(productLikeUrl($unpublished))->assertNotFound();
    $this->actingAs($this->fan)->post(productLikeUrl($hidden))->assertNotFound();
    $this->actingAs($this->fan)->post(productLikeUrl($closed))->assertNotFound();
    $this->actingAs($this->fan)->post(route('shop.products.like.store', [$other, $this->product]))->assertNotFound();
});

it('shows counts and the viewer\'s likes in the home carousels', function () {
    $this->product->like(User::factory()->create());
    $this->product->like($this->fan);

    $this->actingAs($this->fan)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.0.like_count', 2)
            ->where('products.0.liked', true)
            ->where('products.0.can_like', true));

    $this->actingAs($this->product->creator->user)->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.0.liked', false)
            ->where('products.0.can_like', false));
});

it('shows counts to guests too', function () {
    $this->product->like($this->fan);

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.0.like_count', 1)
            ->where('products.0.liked', false)
            ->where('products.0.can_like', true));
});

it('takes a deleted user\'s likes off the counts', function () {
    $this->product->like($this->fan);

    app(AccountDeletion::class)->delete($this->fan);

    expect($this->product->fresh()->like_count)->toBe(0);
});
