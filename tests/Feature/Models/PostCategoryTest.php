<?php

use App\Models\Post;
use App\Models\PostCategory;

it('lists active categories in order', function () {
    $b = PostCategory::factory()->create(['name' => 'B', 'sort_order' => 1]);
    $a = PostCategory::factory()->create(['name' => 'A', 'sort_order' => 0]);
    PostCategory::factory()->archived()->create(['sort_order' => 2]);

    expect(PostCategory::activeSummaries())->toBe([$a->toSummary(), $b->toSummary()]);
});

it('shows only an active category publicly', function () {
    $post = Post::factory()->for(PostCategory::factory(), 'category')->create();
    expect($post->publicCategory()?->id)->toBe($post->post_category_id);

    $post->category->forceFill(['archived_at' => now()])->save();
    expect($post->fresh()->publicCategory())->toBeNull();
});

it('unsets the category when the category is deleted', function () {
    $post = Post::factory()->for(PostCategory::factory(), 'category')->create();
    $post->category->delete();
    expect($post->fresh()->post_category_id)->toBeNull();
});

it('puts a new category last', function () {
    expect(PostCategory::nextSortOrder())->toBe(0);
    PostCategory::factory()->create(['sort_order' => 4]);
    expect(PostCategory::nextSortOrder())->toBe(5);
});
