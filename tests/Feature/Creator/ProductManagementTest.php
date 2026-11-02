<?php

use App\Enums\OrderStatus;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    $this->creator = Creator::factory()->payable()->create();
    $this->actingAs($this->creator->user);
});

/**
 * The product form with a single kind. `stock` and `stock_seen` go to that kind; pass the product to edit its kind.
 */
function productPayload(array $overrides = [], ?Product $product = null): array
{
    $variant = ['id' => $product?->variants()->value('id'), 'name' => null, 'stock' => $overrides['stock'] ?? 10];

    if (array_key_exists('stock_seen', $overrides)) {
        $variant['stock_seen'] = $overrides['stock_seen'];
    }

    unset($overrides['stock'], $overrides['stock_seen']);

    return ['name' => 'アクリルスタンド', 'description' => '説明', 'price' => 1500, 'variants' => [$variant], ...$overrides];
}

it('lists own products only, without deleted ones', function () {
    Product::factory()->for($this->creator)->create(['name' => '自分の商品']);
    Product::factory()->for($this->creator)->create()->delete();
    Product::factory()->create();

    $this->get(route('creator.products.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/products/index')
            ->has('products', 1)
            ->where('products.0.name', '自分の商品')
            ->where('can_publish', true));
});

it('creates, updates and soft-deletes a product', function () {
    $this->post(route('creator.products.store'), productPayload())->assertSessionHasNoErrors();

    $product = $this->creator->products()->sole();
    expect($product)->name->toBe('アクリルスタンド')->price->toBe(1500)->stock->toBe(10)->is_published->toBeFalse();

    $this->put(route('creator.products.update', $product), productPayload(['name' => '新しい名前', 'price' => 2000, 'stock' => 3, 'stock_seen' => 10], $product))
        ->assertSessionHasNoErrors();
    expect($product->fresh())->name->toBe('新しい名前')->price->toBe(2000)->stock->toBe(3);

    $this->delete(route('creator.products.destroy', $product))->assertRedirect(route('creator.products.index'));

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
    $this->get(route('creator.products.index'))->assertInertia(fn (Assert $page) => $page->has('products', 0));
});

it('shows the create and edit pages', function () {
    $product = Product::factory()->for($this->creator)->create();
    $product->images()->create(['path' => 'creators/1/products/a.jpg', 'sort_order' => 0]);

    $this->get(route('creator.products.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('creator/products/edit')->where('product', null));

    $this->get(route('creator.products.edit', $product))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/products/edit')
            ->where('product.id', $product->id)
            ->has('product.images', 1)
            ->has('product.images.0.url'));
});

it('keeps a sale made while the edit form was open', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 1]);
    $product->variants()->decrement('stock');

    $this->put(route('creator.products.update', $product), productPayload(['name' => '新しい名前', 'stock' => 1, 'stock_seen' => 1], $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh())->name->toBe('新しい名前')->stock->toBe(0);
});

it('applies the change of stock on top of sales made meanwhile', function (int $seen, int $submitted, int $current, int $expected) {
    $product = Product::factory()->for($this->creator)->create(['stock' => $current]);

    $this->put(route('creator.products.update', $product), productPayload(['stock' => $submitted, 'stock_seen' => $seen], $product))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->stock)->toBe($expected);
})->with([
    'raised while one sold' => [5, 8, 4, 7],
    'never below zero' => [5, 2, 0, 0],
    'never above the limit' => [0, 5, 99_999, 99_999],
]);

it('requires the stock the form was opened with when updating', function () {
    $product = Product::factory()->for($this->creator)->create(['stock' => 5]);

    $this->put(route('creator.products.update', $product), productPayload(['stock' => 8], $product))
        ->assertSessionHasErrors('variants.0.stock_seen');

    expect($product->fresh()->stock)->toBe(5);
});

it('shows how many are held by pending orders on the edit page', function () {
    $product = Product::factory()->for($this->creator)->create();
    Order::factory()->for($this->creator)->withItem($product, 2)->create();
    Order::factory()->for($this->creator)->withItem($product, 1)->create();
    Order::factory()->for($this->creator)->paid()->withItem($product, 4)->create();
    Order::factory()->for($this->creator)->withItem(Product::factory()->for($this->creator)->create(), 5)->create();
    Order::factory()->for($this->creator)->withItem($product, 3)->create(['status' => OrderStatus::Canceled]);

    $this->get(route('creator.products.edit', $product))
        ->assertInertia(fn (Assert $page) => $page->where('product.variants.0.reserved', 3));
});

it('validates price, stock and name limits', function (array $overrides, string $field) {
    $this->post(route('creator.products.store'), productPayload($overrides))->assertSessionHasErrors($field);
    expect($this->creator->products()->count())->toBe(0);
})->with([
    'price low' => [['price' => 49], 'price'],
    'price high' => [['price' => 1_000_001], 'price'],
    'stock negative' => [['stock' => -1], 'variants.0.stock'],
    'stock high' => [['stock' => 100_000], 'variants.0.stock'],
    'name long' => [['name' => str_repeat('あ', 101)], 'name'],
    'name empty' => [['name' => ''], 'name'],
    'description long' => [['description' => str_repeat('あ', 5001)], 'description'],
]);

it('accepts the boundary values', function () {
    $this->post(route('creator.products.store'), productPayload(['price' => 50, 'stock' => 0, 'name' => str_repeat('あ', 100)]))
        ->assertSessionHasNoErrors();
    $this->post(route('creator.products.store'), productPayload(['price' => 1_000_000, 'stock' => 99_999]))
        ->assertSessionHasNoErrors();

    expect($this->creator->products()->count())->toBe(2);
});

it('refuses to publish before payouts are enabled', function () {
    $creator = Creator::factory()->create();
    $this->actingAs($creator->user);

    $this->post(route('creator.products.store'), productPayload(['is_published' => true]))
        ->assertSessionHasErrors(['is_published' => '口座の登録が済むと公開できます。']);
    expect($creator->products()->count())->toBe(0);

    $product = Product::factory()->for($creator)->unpublished()->create();
    $this->put(route('creator.products.update', $product), productPayload(['stock_seen' => 10, 'is_published' => true], $product))
        ->assertSessionHasErrors('is_published');
    expect($product->fresh()->is_published)->toBeFalse();

    $this->get(route('creator.products.index'))->assertInertia(fn (Assert $page) => $page->where('can_publish', false));
});

it('publishes when payouts are enabled and always allows unpublishing', function () {
    $product = Product::factory()->for($this->creator)->unpublished()->create();

    $this->put(route('creator.products.update', $product), productPayload(['stock_seen' => 10, 'is_published' => true], $product))->assertSessionHasNoErrors();
    expect($product->fresh()->is_published)->toBeTrue();

    $this->actingAs(tap($this->creator)->update(['payouts_enabled' => false])->user);
    $this->put(route('creator.products.update', $product), productPayload(['stock_seen' => 10, 'is_published' => true], $product))->assertSessionHasNoErrors();
    expect($product->fresh()->is_published)->toBeTrue();

    $this->put(route('creator.products.update', $product), productPayload(['stock_seen' => 10, 'is_published' => false], $product))->assertSessionHasNoErrors();
    expect($product->fresh()->is_published)->toBeFalse();
});

it('uploads images to the creator products directory and deletes the file with the row', function () {
    $product = Product::factory()->for($this->creator)->create();

    $this->post(route('creator.products.images.store', $product), ['image' => UploadedFile::fake()->image('a.jpg')])
        ->assertSessionHasNoErrors();
    $this->post(route('creator.products.images.store', $product), ['image' => UploadedFile::fake()->image('b.png')])
        ->assertSessionHasNoErrors();

    $images = $product->images()->get();
    expect($images)->toHaveCount(2)->and($images[1]->sort_order)->toBeGreaterThan($images[0]->sort_order);
    Storage::disk('public')->assertExists($images->pluck('path')->all());
    expect($images[0]->path)->toStartWith("creators/{$this->creator->id}/products/");

    $this->delete(route('creator.products.images.destroy', [$product, $images[0]]))->assertRedirect();

    expect(ProductImage::find($images[0]->id))->toBeNull();
    Storage::disk('public')->assertMissing($images[0]->path);
    Storage::disk('public')->assertExists($images[1]->path);
});

it('rejects non-image uploads', function () {
    $product = Product::factory()->for($this->creator)->create();

    $this->post(route('creator.products.images.store', $product), ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('image');
    expect($product->images()->count())->toBe(0);
});

it('limits images to ten and reorders them', function () {
    $product = Product::factory()->for($this->creator)->create();

    foreach (range(1, 10) as $i) {
        $product->images()->create(['path' => "creators/1/products/{$i}.jpg", 'sort_order' => $i]);
    }

    $this->post(route('creator.products.images.store', $product), ['image' => UploadedFile::fake()->image('11.jpg')])
        ->assertSessionHasErrors(['image' => '画像は10枚までです。']);
    expect($product->images()->count())->toBe(10);

    $ids = $product->images()->pluck('id')->reverse()->values()->all();
    $this->put(route('creator.products.images.reorder', $product), ['ids' => $ids])->assertSessionHasNoErrors();

    expect($product->images()->pluck('id')->all())->toBe($ids);
});

it('only reorders with exactly the products own image ids', function () {
    $product = Product::factory()->for($this->creator)->create();
    $a = $product->images()->create(['path' => 'creators/1/products/a.jpg', 'sort_order' => 0]);
    $b = $product->images()->create(['path' => 'creators/1/products/b.jpg', 'sort_order' => 1]);
    $foreign = Product::factory()->create()->images()->create(['path' => 'creators/2/products/f.jpg', 'sort_order' => 0]);

    $this->put(route('creator.products.images.reorder', $product), ['ids' => [$b->id, $foreign->id]])->assertSessionHasErrors('ids');
    $this->put(route('creator.products.images.reorder', $product), ['ids' => [$b->id]])->assertSessionHasErrors('ids');
    $this->put(route('creator.products.images.reorder', $product), ['ids' => [$b->id, $b->id]])->assertSessionHasErrors('ids');

    expect($product->images()->pluck('id')->all())->toBe([$a->id, $b->id]);
});

it('forbids editing another creators product', function (string $method, string $route, array $data) {
    $product = Product::factory()->create();
    $image = $product->images()->create(['path' => 'creators/2/products/f.jpg', 'sort_order' => 0]);

    $this->{$method}(route($route, ['product' => $product, 'image' => $image]), $data)->assertForbidden();
})->with([
    'edit' => ['get', 'creator.products.edit', []],
    'update' => ['put', 'creator.products.update', fn () => productPayload()],
    'destroy' => ['delete', 'creator.products.destroy', []],
    'image store' => ['post', 'creator.products.images.store', fn () => ['image' => UploadedFile::fake()->image('a.jpg')]],
    'image destroy' => ['delete', 'creator.products.images.destroy', []],
    'image reorder' => ['put', 'creator.products.images.reorder', ['ids' => []]],
]);

it('requires a creator account', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('creator.products.index'))->assertRedirect();
});

it('does not delete an image that belongs to a different product', function () {
    Storage::disk('public')->put('creators/2/products/f.jpg', 'x');
    $own = Product::factory()->for($this->creator)->create();
    $foreign = Product::factory()->create()->images()->create(['path' => 'creators/2/products/f.jpg', 'sort_order' => 0]);

    $this->delete(route('creator.products.images.destroy', [$own, $foreign]))->assertForbidden();

    expect(ProductImage::find($foreign->id))->not->toBeNull();
    Storage::disk('public')->assertExists('creators/2/products/f.jpg');
});
