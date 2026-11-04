<?php

use App\Models\PostCategory;
use Inertia\Testing\AssertableInertia as Assert;

it('passes the active post categories to the home page in display order', function () {
    PostCategory::factory()->create(['name' => '二番', 'slug' => 'second', 'sort_order' => 2]);
    PostCategory::factory()->create(['name' => '一番', 'slug' => 'first', 'sort_order' => 1]);
    PostCategory::factory()->archived()->create(['name' => '済', 'slug' => 'gone', 'sort_order' => 0]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('postCategories', 2)
        ->where('postCategories.0.slug', 'first')
        ->where('postCategories.1.slug', 'second'));
});
