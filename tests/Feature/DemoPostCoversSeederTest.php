<?php

use App\Models\Post;
use Database\Seeders\DemoPostCoversSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakePrivateDisk());

it('gives every post without a cover a 16:9 cover and a thumbnail', function () {
    $posts = Post::factory()->count(2)->published()->sequence(['title' => 'a'], ['title' => 'c'])->create();

    $this->seed(DemoPostCoversSeeder::class);

    foreach ($posts as $post) {
        $cover = $post->fresh()->cover;
        Storage::disk('private')->assertExists([$cover->path, $cover->thumb_path]);
        [$width, $height] = getimagesizefromstring(Storage::disk('private')->get($cover->path));
        expect([$width, $height])->toBe([1280, 720]);
    }
});

it('leaves posts that have a cover alone', function () {
    $post = Post::factory()->published()->create();

    $this->seed(DemoPostCoversSeeder::class);
    $cover = $post->fresh()->cover_file_id;
    $this->seed(DemoPostCoversSeeder::class);

    expect($post->fresh()->cover_file_id)->toBe($cover)
        ->and($post->files()->count())->toBe(1);
});
