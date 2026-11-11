<?php

use App\Models\Creator;
use App\Models\Post;
use Database\Seeders\DemoCreatorsSeeder;
use Database\Seeders\DemoPostCoversSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakePrivateDisk();
    Storage::fake('public');
});

it('gives the posts of creators with a demo header a 16:9 cover and a thumbnail', function () {
    $creator = Creator::factory()->create(['slug' => 'yuki-cooking']);
    app(DemoCreatorsSeeder::class)->paintImages($creator, DemoCreatorsSeeder::palette('yuki-cooking'));
    $posts = Post::factory()->count(2)->for($creator)->published()->sequence(['title' => 'a'], ['title' => 'c'])->create();
    $noHeader = Post::factory()->published()->create();

    $this->seed(DemoPostCoversSeeder::class);

    foreach ($posts as $post) {
        $cover = $post->fresh()->cover;
        Storage::disk('private')->assertExists([$cover->path, $cover->thumb_path]);
        [$width, $height] = getimagesizefromstring(Storage::disk('private')->get($cover->path));
        expect([$width, $height])->toBe([1280, 720]);
    }

    expect($noHeader->fresh()->cover_file_id)->toBeNull();
});

it('leaves posts that have a cover alone', function () {
    $creator = Creator::factory()->create(['slug' => 'sakura-illust']);
    app(DemoCreatorsSeeder::class)->paintImages($creator, DemoCreatorsSeeder::palette('sakura-illust'));
    $post = Post::factory()->for($creator)->published()->create();

    $this->seed(DemoPostCoversSeeder::class);
    $cover = $post->fresh()->cover_file_id;
    $this->seed(DemoPostCoversSeeder::class);

    expect($post->fresh()->cover_file_id)->toBe($cover)
        ->and($post->files()->count())->toBe(1);
});
