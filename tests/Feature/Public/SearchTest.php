<?php

use App\Models\Creator;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Product;
use App\Models\ProductCategory;
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

it('searches only the chosen kind', function (string $type, int $creators, int $products, int $posts) {
    Creator::factory()->create(['display_name' => '星空']);
    Product::factory()->create(['name' => '星空']);
    Post::factory()->published()->create(['title' => '星空']);

    $this->get(route('search', ['q' => '星空', 'type' => $type]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('type', $type)
            ->has('creators', $creators)
            ->has('products', $products)
            ->has('posts', $posts));
})->with([
    'all' => ['all', 1, 1, 1],
    'creators' => ['creators', 1, 0, 0],
    'products' => ['products', 0, 1, 0],
    'posts' => ['posts', 0, 0, 1],
]);

it('searches everything for an unknown kind', function (mixed $type) {
    Product::factory()->create(['name' => '星空']);

    $this->get(route('search', ['q' => '星空', 'type' => $type]))
        ->assertInertia(fn (Assert $page) => $page->where('type', 'all')->has('products', 1));
})->with(['bogus' => 'bogus', 'not a string' => [['products']]]);

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

it('narrows products by category', function () {
    $category = ProductCategory::factory()->create(['slug' => 'sticker']);
    $inside = Product::factory()->create(['name' => '星空シール', 'product_category_id' => $category->id]);
    Product::factory()->create(['name' => '星空マグ']);
    Creator::factory()->create(['display_name' => '星空']);
    Post::factory()->published()->create(['title' => '星空の写真']);

    $this->get(route('search', ['q' => '星空', 'category' => 'sticker']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('category', 'sticker')
            ->has('categories', 1)
            ->has('products', 1)
            ->where('products.0.id', $inside->id)
            ->has('creators', 1)
            ->has('posts', 1));
});

it('ignores an unknown or archived category', function (mixed $value) {
    ProductCategory::factory()->create();
    $category = ProductCategory::factory()->create(['slug' => 'sticker']);
    Product::factory()->create(['name' => '星空シール', 'product_category_id' => $category->id]);
    Product::factory()->create(['name' => '星空マグ']);

    $this->get(route('search', ['q' => '星空', 'category' => value($value)]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('category', null)
            ->has('products', 2));
})->with([
    'unknown' => 'nope',
    'archived' => fn () => ProductCategory::factory()->archived()->create()->slug,
    'array' => [['x']],
]);

it('sends no categories and no filter without a keyword', function () {
    ProductCategory::factory()->create(['slug' => 'sticker']);

    $this->get(route('search', ['category' => 'sticker']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('category', null)
            ->where('categories', []));
});

it('narrows posts by post category', function () {
    $category = PostCategory::factory()->create(['slug' => 'diary']);
    $inside = Post::factory()->published()->create(['title' => '星空の日記', 'post_category_id' => $category->id]);
    Post::factory()->published()->create(['title' => '星空の写真']);
    Creator::factory()->create(['display_name' => '星空']);
    Product::factory()->create(['name' => '星空マグ']);

    $this->get(route('search', ['q' => '星空', 'post_category' => 'diary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('postCategory', 'diary')
            ->has('postCategories', 1)
            ->has('posts', 1)
            ->where('posts.0.id', $inside->id)
            ->has('creators', 1)
            ->has('products', 1));
});

it('never lists a paid post even when it matches the category', function () {
    $category = PostCategory::factory()->create(['slug' => 'diary']);
    Post::factory()->published()->requiring(500)->create(['title' => '星空の有料日記', 'post_category_id' => $category->id]);

    $this->get(route('search', ['q' => '星空', 'post_category' => 'diary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('postCategory', 'diary')
            ->has('posts', 0));
});

it('ignores an unknown or archived post category', function (mixed $value) {
    PostCategory::factory()->create();
    $category = PostCategory::factory()->create(['slug' => 'diary']);
    Post::factory()->published()->create(['title' => '星空の日記', 'post_category_id' => $category->id]);
    Post::factory()->published()->create(['title' => '星空の写真']);

    $this->get(route('search', ['q' => '星空', 'post_category' => value($value)]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('postCategory', null)
            ->has('posts', 2));
})->with([
    'unknown' => 'nope',
    'archived' => fn () => PostCategory::factory()->archived()->create()->slug,
    'array' => [['x']],
]);

it('sends no post categories and no post filter without a keyword', function () {
    PostCategory::factory()->create(['slug' => 'diary']);

    $this->get(route('search', ['post_category' => 'diary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('postCategory', null)
            ->where('postCategories', []));
});

it('combines the product and post category filters', function () {
    $productCategory = ProductCategory::factory()->create(['slug' => 'sticker']);
    $postCategory = PostCategory::factory()->create(['slug' => 'diary']);
    $product = Product::factory()->create(['name' => '星空シール', 'product_category_id' => $productCategory->id]);
    Product::factory()->create(['name' => '星空マグ']);
    $post = Post::factory()->published()->create(['title' => '星空の日記', 'post_category_id' => $postCategory->id]);
    Post::factory()->published()->create(['title' => '星空の写真']);

    $this->get(route('search', ['q' => '星空', 'category' => 'sticker', 'post_category' => 'diary']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('category', 'sticker')
            ->where('postCategory', 'diary')
            ->has('products', 1)
            ->where('products.0.id', $product->id)
            ->has('posts', 1)
            ->where('posts.0.id', $post->id));
});
