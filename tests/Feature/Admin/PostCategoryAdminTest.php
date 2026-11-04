<?php

use App\Models\AdminAction;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\ProductCategory;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

function postCategoryNames(): array
{
    return PostCategory::query()->ordered()->pluck('name')->all();
}

function postCategoryWithOrder(string $name, int $order): PostCategory
{
    return PostCategory::factory()->create(['name' => $name, 'slug' => 'slug-'.strtolower($name), 'sort_order' => $order]);
}

it('hides the category admin from non-admins', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.post-categories.index'))->assertNotFound();
});

it('lists categories with their post counts', function () {
    $first = PostCategory::factory()->create(['name' => 'A', 'slug' => 'a', 'sort_order' => 0]);
    $second = PostCategory::factory()->archived()->create(['name' => 'B', 'slug' => 'b', 'sort_order' => 1]);
    Post::factory()->count(2)->create(['post_category_id' => $first->id]);

    $this->actingAs($this->admin)->get(route('admin.post-categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/categories')
            ->where('kind', 'posts')
            ->where('categories', [
                ['id' => $first->id, 'name' => 'A', 'slug' => 'a', 'archived' => false, 'usage_count' => 2],
                ['id' => $second->id, 'name' => 'B', 'slug' => 'b', 'archived' => true, 'usage_count' => 0],
            ]));
});

it('adds a category at the end', function () {
    PostCategory::factory()->create(['sort_order' => 4]);

    $this->actingAs($this->admin)->post(route('admin.post-categories.store'), ['name' => 'イラスト', 'slug' => 'illust'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを追加しました。']);

    $category = PostCategory::query()->where('slug', 'illust')->sole();
    expect($category->name)->toBe('イラスト')
        ->and($category->sort_order)->toBe(5)
        ->and(AdminAction::sole())->action->toBe('post_category.created')->target_id->toBe($category->id);
});

it('validates name and slug', function (array $input, string $field) {
    PostCategory::factory()->create(['name' => '既存', 'slug' => 'taken']);

    $this->actingAs($this->admin)->post(route('admin.post-categories.store'), $input)
        ->assertSessionHasErrors($field);

    expect(PostCategory::query()->count())->toBe(1);
})->with([
    'blank name' => [['name' => '', 'slug' => 'ok'], 'name'],
    'long name' => [['name' => str_repeat('あ', 31), 'slug' => 'ok'], 'name'],
    'bad slug' => [['name' => 'OK', 'slug' => 'Bad_Slug'], 'slug'],
    'duplicate slug' => [['name' => '別名', 'slug' => 'taken'], 'slug'],
    'duplicate name' => [['name' => '既存', 'slug' => 'new'], 'name'],
]);

it('renames a category keeping its own slug', function () {
    $category = PostCategory::factory()->create(['name' => '旧', 'slug' => 'same']);

    $this->actingAs($this->admin)->put(route('admin.post-categories.update', $category), ['name' => '新', 'slug' => 'same'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを更新しました。']);

    expect($category->refresh()->name)->toBe('新')
        ->and($category->slug)->toBe('same')
        ->and(AdminAction::sole()->action)->toBe('post_category.updated');

    $this->actingAs($this->admin)->put(route('admin.post-categories.update', $category), ['name' => '新', 'slug' => 'same'])
        ->assertSessionHasNoErrors();
});

it('moves a category up and down', function () {
    postCategoryWithOrder('A', 0);
    $b = postCategoryWithOrder('B', 1);
    postCategoryWithOrder('C', 2);

    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $b), ['direction' => 'up'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを更新しました。']);
    expect(postCategoryNames())->toBe(['B', 'A', 'C']);

    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $b), ['direction' => 'down']);
    expect(postCategoryNames())->toBe(['A', 'B', 'C']);

    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $b), ['direction' => 'down']);
    expect(postCategoryNames())->toBe(['A', 'C', 'B'])
        ->and(AdminAction::query()->where('action', 'post_category.moved')->count())->toBe(3);
});

it('moves a category even when sort orders are equal', function () {
    postCategoryWithOrder('A', 0);
    $b = postCategoryWithOrder('B', 0);
    postCategoryWithOrder('C', 0);

    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $b), ['direction' => 'up']);

    expect(postCategoryNames())->toBe(['B', 'A', 'C']);
});

it('leaves the order alone at the ends', function () {
    $a = postCategoryWithOrder('A', 0);
    postCategoryWithOrder('B', 1);
    $c = postCategoryWithOrder('C', 2);

    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $a), ['direction' => 'up'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを更新しました。']);
    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $c), ['direction' => 'down']);

    expect(postCategoryNames())->toBe(['A', 'B', 'C']);
});

it('rejects an unknown direction', function () {
    $category = postCategoryWithOrder('A', 0);

    $this->actingAs($this->admin)->post(route('admin.post-categories.move', $category), ['direction' => 'left'])
        ->assertSessionHasErrors('direction');
});

it('archives and unarchives a category', function () {
    $category = PostCategory::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.post-categories.archive', $category), ['archived' => true])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリをアーカイブしました。']);
    expect($category->refresh()->archived_at)->not->toBeNull();

    $this->actingAs($this->admin)->patch(route('admin.post-categories.archive', $category), ['archived' => false])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを再開しました。']);
    expect($category->refresh()->archived_at)->toBeNull()
        ->and(AdminAction::query()->orderBy('id')->pluck('action')->all())->toBe(['post_category.archived', 'post_category.unarchived']);
});

it('deletes an unused category', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->create(['post_category_id' => null]);

    $this->actingAs($this->admin)->delete(route('admin.post-categories.destroy', $category))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'カテゴリを削除しました。']);

    expect(PostCategory::query()->count())->toBe(0)
        ->and($post->refresh()->post_category_id)->toBeNull()
        ->and(AdminAction::sole())->action->toBe('post_category.deleted')->target_id->toBe($category->id);
});

it('refuses to delete a category in use', function () {
    $category = PostCategory::factory()->create();
    Post::factory()->create(['post_category_id' => $category->id]);

    $this->actingAs($this->admin)->delete(route('admin.post-categories.destroy', $category))
        ->assertSessionHasErrors(['category' => '投稿が付いているカテゴリは削除できません。アーカイブしてください。']);

    expect(PostCategory::query()->count())->toBe(1)
        ->and(AdminAction::query()->count())->toBe(0);
});

it('does not touch product categories from the post routes', function () {
    $product = ProductCategory::factory()->create(['name' => '商品側']);
    PostCategory::query()->whereKey($product->id)->delete();

    $this->actingAs($this->admin)->put(route('admin.post-categories.update', $product->id), ['name' => '変更', 'slug' => 'changed'])
        ->assertNotFound();

    expect($product->fresh()->name)->toBe('商品側');
});

it('checks uniqueness within post categories only', function () {
    ProductCategory::factory()->create(['name' => '共通', 'slug' => 'shared']);

    $this->actingAs($this->admin)->post(route('admin.post-categories.store'), ['name' => '共通', 'slug' => 'shared'])
        ->assertSessionHasNoErrors();

    expect(PostCategory::query()->where('slug', 'shared')->exists())->toBeTrue();
});
