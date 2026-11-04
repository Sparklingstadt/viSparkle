<?php

use App\Models\Post;
use App\Models\PostLike;
use Database\Seeders\DemoPostLikesSeeder;

it('gives each public post one to ten likes', function () {
    $posts = Post::factory()->published()->count(5)->create();

    $this->seed(DemoPostLikesSeeder::class);

    $posts->each(function (Post $post) {
        $post->refresh();

        expect($post->like_count)->toBeBetween(1, 10)
            ->and($post->likes()->count())->toBe($post->like_count);
    });
});

it('leaves paid, hidden and draft posts alone', function () {
    Post::factory()->published()->requiring(500)->create();
    Post::factory()->published()->hidden()->create();
    Post::factory()->draft()->create();

    $this->seed(DemoPostLikesSeeder::class);

    expect(PostLike::query()->count())->toBe(0);
});

it('changes nothing when seeded twice', function () {
    Post::factory()->published()->count(3)->create();

    $this->seed(DemoPostLikesSeeder::class);
    $likes = PostLike::query()->count();
    $this->seed(DemoPostLikesSeeder::class);

    expect(PostLike::query()->count())->toBe($likes);
});
