<?php

use App\Models\AdminAction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function categoryNames(): array
{
    return ProductCategory::query()->ordered()->pluck('name')->all();
}

function categoryWithOrder(string $name, int $order): ProductCategory
{
    return ProductCategory::factory()->create(['name' => $name, 'slug' => 'slug-'.strtolower($name), 'sort_order' => $order]);
}

it('hides the category admin from non-admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.categories.index'))->assertNotFound();
});

it('lists categories with their product counts', function () {
    $first = ProductCategory::factory()->create(['name' => 'A', 'slug' => 'a', 'sort_order' => 0]);
    $second = ProductCategory::factory()->archived()->create(['name' => 'B', 'slug' => 'b', 'sort_order' => 1]);
    Product::factory()->count(2)->create(['product_category_id' => $first->id]);
    Product::factory()->create(['product_category_id' => $first->id])->delete();

    $this->actingAs($this->admin)->get(route('admin.categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/categories')
            ->where('categories', [
                ['id' => $first->id, 'name' => 'A', 'slug' => 'a', 'archived' => false, 'products_count' => 2],
                ['id' => $second->id, 'name' => 'B', 'slug' => 'b', 'archived' => true, 'products_count' => 0],
            ]));
});

it('adds a category at the end', function () {
    ProductCategory::factory()->create(['sort_order' => 4]);

    $this->actingAs($this->admin)->post(route('admin.categories.store'), ['name' => 'イラスト', 'slug' => 'illust'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを追加しました。']);

    $category = ProductCategory::query()->where('slug', 'illust')->sole();
    expect($category->name)->toBe('イラスト')
        ->and($category->sort_order)->toBe(5)
        ->and(AdminAction::sole())->action->toBe('category.created')->target_id->toBe($category->id);
});

it('validates name and slug', function (array $input, string $field) {
    ProductCategory::factory()->create(['name' => '既存', 'slug' => 'taken']);

    $this->actingAs($this->admin)->post(route('admin.categories.store'), $input)
        ->assertSessionHasErrors($field);

    expect(ProductCategory::query()->count())->toBe(1);
})->with([
    'blank name' => [['name' => '', 'slug' => 'ok'], 'name'],
    'long name' => [['name' => str_repeat('あ', 31), 'slug' => 'ok'], 'name'],
    'bad slug' => [['name' => 'OK', 'slug' => 'Bad_Slug'], 'slug'],
    'duplicate slug' => [['name' => '別名', 'slug' => 'taken'], 'slug'],
    'duplicate name' => [['name' => '既存', 'slug' => 'new'], 'name'],
]);

it('renames a category keeping its own slug', function () {
    $category = ProductCategory::factory()->create(['name' => '旧', 'slug' => 'same']);

    $this->actingAs($this->admin)->put(route('admin.categories.update', $category), ['name' => '新', 'slug' => 'same'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを更新しました。']);

    expect($category->refresh()->name)->toBe('新')
        ->and($category->slug)->toBe('same')
        ->and(AdminAction::sole()->action)->toBe('category.updated');

    $this->actingAs($this->admin)->put(route('admin.categories.update', $category), ['name' => '新', 'slug' => 'same'])
        ->assertSessionHasNoErrors();
});

it('moves a category up and down', function () {
    categoryWithOrder('A', 0);
    $b = categoryWithOrder('B', 1);
    categoryWithOrder('C', 2);

    $this->actingAs($this->admin)->post(route('admin.categories.move', $b), ['direction' => 'up'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを更新しました。']);
    expect(categoryNames())->toBe(['B', 'A', 'C']);

    $this->actingAs($this->admin)->post(route('admin.categories.move', $b), ['direction' => 'down']);
    expect(categoryNames())->toBe(['A', 'B', 'C']);

    $this->actingAs($this->admin)->post(route('admin.categories.move', $b), ['direction' => 'down']);
    expect(categoryNames())->toBe(['A', 'C', 'B'])
        ->and(AdminAction::query()->where('action', 'category.moved')->count())->toBe(3);
});

it('moves a category even when sort orders are equal', function () {
    categoryWithOrder('A', 0);
    $b = categoryWithOrder('B', 0);
    categoryWithOrder('C', 0);

    $this->actingAs($this->admin)->post(route('admin.categories.move', $b), ['direction' => 'up']);

    expect(categoryNames())->toBe(['B', 'A', 'C']);
});

it('leaves the order alone at the ends', function () {
    $a = categoryWithOrder('A', 0);
    categoryWithOrder('B', 1);
    $c = categoryWithOrder('C', 2);

    $this->actingAs($this->admin)->post(route('admin.categories.move', $a), ['direction' => 'up'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを更新しました。']);
    $this->actingAs($this->admin)->post(route('admin.categories.move', $c), ['direction' => 'down']);

    expect(categoryNames())->toBe(['A', 'B', 'C']);
});

it('rejects an unknown direction', function () {
    $category = categoryWithOrder('A', 0);

    $this->actingAs($this->admin)->post(route('admin.categories.move', $category), ['direction' => 'left'])
        ->assertSessionHasErrors('direction');
});

it('archives and unarchives a category', function () {
    $category = ProductCategory::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.categories.archive', $category), ['archived' => true])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリをアーカイブしました。']);
    expect($category->refresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->admin)->patch(route('admin.categories.archive', $category), ['archived' => false])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを再開しました。']);
    expect($category->refresh()->archived_at)->toBeNull()
        ->and(AdminAction::query()->orderBy('id')->pluck('action')->all())->toBe(['category.archived', 'category.unarchived']);
});

it('deletes an unused category', function () {
    $category = ProductCategory::factory()->create();
    $product = Product::factory()->create(['product_category_id' => $category->id]);
    $product->delete();

    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを削除しました。']);

    expect(ProductCategory::query()->count())->toBe(0)
        ->and($product->refresh()->product_category_id)->toBeNull()
        ->and(AdminAction::sole())->action->toBe('category.deleted')->target_id->toBe($category->id);
});

it('refuses to delete a category in use', function () {
    $category = ProductCategory::factory()->create();
    Product::factory()->create(['product_category_id' => $category->id]);

    $this->actingAs($this->admin)->delete(route('admin.categories.destroy', $category))
        ->assertSessionHasErrors(['category' => '商品が付いているカテゴリは削除できません。アーカイブしてください。']);

    expect(ProductCategory::query()->count())->toBe(1)
        ->and(AdminAction::query()->count())->toBe(0);
});
