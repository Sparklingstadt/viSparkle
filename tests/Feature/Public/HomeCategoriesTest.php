<?php

use App\Models\ProductCategory;
use Inertia\Testing\AssertableInertia as Assert;

it('passes the active categories to the home page in display order', function () {
    ProductCategory::factory()->create(['name' => '二番', 'slug' => 'second', 'sort_order' => 2]);
    ProductCategory::factory()->create(['name' => '一番', 'slug' => 'first', 'sort_order' => 1]);
    ProductCategory::factory()->archived()->create(['name' => '済', 'slug' => 'gone', 'sort_order' => 0]);

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('categories', 2)
        ->where('categories.0.slug', 'first')
        ->where('categories.1.slug', 'second'));
});
