<?php

use App\Enums\PostStatus;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostCategory;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->creator = Creator::factory()->create();
    $this->actingAs($this->creator->user);
});

function postCategoryPayload(array $overrides = []): array
{
    return ['action' => 'save', 'title' => 'タイトル', 'excerpt' => null, 'body' => null, 'required_price' => 0, 'cover_file_id' => null, ...$overrides];
}

it('saves a category on the post', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), postCategoryPayload(['post_category_id' => $category->id]))
        ->assertRedirect(route('creator.posts.edit', $post))
        ->assertSessionHasNoErrors();

    expect($post->fresh()->post_category_id)->toBe($category->id);
});

it('saves a category when publishing', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), postCategoryPayload(['action' => 'publish', 'post_category_id' => $category->id]))
        ->assertSessionHasNoErrors();

    expect($post->fresh())->post_category_id->toBe($category->id)->status->toBe(PostStatus::Published);
});

it('clears the category', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->for($this->creator)->create(['post_category_id' => $category->id]);

    $this->put(route('creator.posts.update', $post), postCategoryPayload(['post_category_id' => '']))
        ->assertSessionHasNoErrors();

    expect($post->fresh()->post_category_id)->toBeNull();
});

it('clears the category when it is omitted, like the other fields of the full-form update', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->for($this->creator)->create(['post_category_id' => $category->id]);

    $this->put(route('creator.posts.update', $post), postCategoryPayload())
        ->assertSessionHasNoErrors();

    expect($post->fresh()->post_category_id)->toBeNull();
});

it('offers active categories in order on the form', function () {
    $second = PostCategory::factory()->create(['sort_order' => 2]);
    $first = PostCategory::factory()->create(['sort_order' => 1]);
    PostCategory::factory()->archived()->create(['sort_order' => 0]);
    $post = Post::factory()->for($this->creator)->create(['post_category_id' => $second->id]);

    $this->get(route('creator.posts.edit', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.category_id', $second->id)
            ->where('categories', [
                ['id' => $first->id, 'name' => $first->name, 'archived' => false],
                ['id' => $second->id, 'name' => $second->name, 'archived' => false],
            ]));
});

it('rejects an archived or missing category', function (bool $archived) {
    $id = $archived ? PostCategory::factory()->archived()->create()->id : 999999;
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), postCategoryPayload(['post_category_id' => $id]))
        ->assertSessionHasErrors(['post_category_id' => 'カテゴリが正しくありません']);

    expect($post->fresh()->post_category_id)->toBeNull();
})->with([[true], [false]]);

it('keeps an archived category the post already has', function () {
    $category = PostCategory::factory()->create();
    $post = Post::factory()->for($this->creator)->create(['post_category_id' => $category->id]);
    $category->forceFill(['archived_at' => now()])->save();

    $this->get(route('creator.posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories', [['id' => $category->id, 'name' => $category->name, 'archived' => true]]));

    $this->put(route('creator.posts.update', $post), postCategoryPayload(['title' => '改題', 'post_category_id' => $category->id]))
        ->assertSessionHasNoErrors();

    expect($post->fresh())->title->toBe('改題')->post_category_id->toBe($category->id);
});

it('rejects an archived category the post does not have', function () {
    $current = PostCategory::factory()->create();
    $archived = PostCategory::factory()->archived()->create();
    $post = Post::factory()->for($this->creator)->create(['post_category_id' => $current->id]);

    $this->put(route('creator.posts.update', $post), postCategoryPayload(['post_category_id' => $archived->id]))
        ->assertSessionHasErrors(['post_category_id' => 'カテゴリが正しくありません']);

    expect($post->fresh()->post_category_id)->toBe($current->id);
});
