<?php

use App\Models\Creator;
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

function productPayload(array $overrides = []): array
{
    return ['name' => 'アクリルスタンド', 'description' => '説明', 'price' => 1500, 'stock' => 10, ...$overrides];
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

    $this->put(route('creator.products.update', $product), productPayload(['name' => '新しい名前', 'price' => 2000, 'stock' => 3]))
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

it('validates price, stock and name limits', function (array $overrides, string $field) {
    $this->post(route('creator.products.store'), productPayload($overrides))->assertSessionHasErrors($field);
    expect($this->creator->products()->count())->toBe(0);
})->with([
    'price low' => [['price' => 49], 'price'],
    'price high' => [['price' => 1_000_001], 'price'],
    'stock negative' => [['stock' => -1], 'stock'],
    'stock high' => [['stock' => 100_000], 'stock'],
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
    $this->put(route('creator.products.update', $product), productPayload(['is_published' => true]))
        ->assertSessionHasErrors('is_published');
    expect($product->fresh()->is_published)->toBeFalse();

    $this->get(route('creator.products.index'))->assertInertia(fn (Assert $page) => $page->where('can_publish', false));
});

it('publishes when payouts are enabled and always allows unpublishing', function () {
    $product = Product::factory()->for($this->creator)->unpublished()->create();

    $this->put(route('creator.products.update', $product), productPayload(['is_published' => true]))->assertSessionHasNoErrors();
    expect($product->fresh()->is_published)->toBeTrue();

    $this->actingAs(tap($this->creator)->update(['payouts_enabled' => false])->user);
    $this->put(route('creator.products.update', $product), productPayload(['is_published' => true]))->assertSessionHasNoErrors();
    expect($product->fresh()->is_published)->toBeTrue();

    $this->put(route('creator.products.update', $product), productPayload(['is_published' => false]))->assertSessionHasNoErrors();
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
