<?php

use App\Models\User;

dataset('creator pages', [
    'plans' => ['creator.plans.index'],
    'posts' => ['creator.posts.index'],
    'supporters' => ['creator.supporters.index'],
]);

it('non-creator users get 403 on creator pages', function (string $route) {
    $this->actingAs(User::factory()->create())->get(route($route))->assertForbidden();
})->with('creator pages');

it('the creator can open creator pages', function (string $route) {
    $this->actingAs(User::factory()->creator()->create())->get(route($route))->assertOk();
})->with('creator pages');

it('sends guests to login', function () {
    $this->get(route('creator.posts.index'))->assertRedirect(route('login'));
});
