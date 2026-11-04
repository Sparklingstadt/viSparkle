<?php

use App\Models\Creator;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('finds creators, products and free posts by keyword', function () {
    $creator = Creator::factory()->create(['display_name' => '星空スタジオ', 'bio' => '']);
    $byBio = Creator::factory()->create(['display_name' => '別の人', 'bio' => '星空を描いています']);
    Creator::factory()->create(['display_name' => '無関係', 'bio' => '']);
    Product::factory()->create(['name' => '星空マグカップ', 'description' => '']);
    Product::factory()->create(['name' => '湯のみ', 'description' => '星空の柄です']);
    Product::factory()->create(['name' => '無関係', 'description' => '']);
    Post::factory()->published()->create(['title' => '星空の写真', 'excerpt' => '']);
    Post::factory()->published()->create(['title' => '近況', 'excerpt' => '星空を見ました']);
    Post::factory()->published()->create(['title' => '無関係', 'excerpt' => '', 'body' => '星空']);

    $this->get(route('search', ['q' => '星空']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/search')
            ->where('q', '星空')
            ->has('creators', 2)
            ->where('creators.0.slug', $byBio->slug)
            ->where('creators.1.slug', $creator->slug)
            ->has('products', 2)
            ->has('products.0.creator')
            ->has('posts', 2));
});

it('leaves out what the public pages do not show', function () {
    $suspended = Creator::factory()->for(User::factory()->suspended())->create(['display_name' => '星空A']);
    $closed = Creator::factory()->closed()->create(['display_name' => '星空B']);
    Product::factory()->for($suspended)->create(['name' => '星空1']);
    Product::factory()->for($closed)->create(['name' => '星空2']);
    Product::factory()->unpublished()->create(['name' => '星空3']);
    Product::factory()->create(['name' => '星空4', 'hidden_at' => now()]);
    Product::factory()->create(['name' => '星空5'])->delete();
    Post::factory()->for($suspended)->published()->create(['title' => '星空1']);
    Post::factory()->published()->requiring(500)->create(['title' => '星空2']);
    Post::factory()->published()->hidden()->create(['title' => '星空3']);
    Post::factory()->draft()->create(['title' => '星空4']);

    $this->get(route('search', ['q' => '星空']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('creators', 0)
            ->has('products', 0)
            ->has('posts', 0));
});

it('shows at most eight results of each kind', function () {
    Creator::factory()->count(9)->create(['display_name' => '星空']);
    Product::factory()->count(9)->create(['name' => '星空']);
    Post::factory()->published()->count(9)->create(['title' => '星空']);

    $this->get(route('search', ['q' => '星空']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('creators', 8)
            ->has('products', 8)
            ->has('posts', 8));
});

it('searches nothing without a keyword', function (array $query) {
    Creator::factory()->create();
    Product::factory()->create();
    Post::factory()->published()->create();

    $this->get(route('search', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('q', '')
            ->has('creators', 0)
            ->has('products', 0)
            ->has('posts', 0));
})->with([
    'missing' => [[]],
    'blank' => [['q' => "  \u{3000} "]],
    'not a string' => [['q' => ['星空']]],
]);

it('treats wildcard characters as plain text', function () {
    Product::factory()->create(['name' => '100%果汁', 'description' => '']);
    Product::factory()->create(['name' => '果汁', 'description' => '']);

    $this->get(route('search', ['q' => '%']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products', 1)
            ->where('products.0.name', '100%果汁'));
});

it('cuts a keyword down to one hundred characters', function () {
    $this->get(route('search', ['q' => str_repeat('あ', 101)]))
        ->assertInertia(fn (Assert $page) => $page->where('q', str_repeat('あ', 100)));
});
