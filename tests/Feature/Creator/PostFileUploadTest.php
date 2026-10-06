<?php

use App\Enums\PostFileKind;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostFile;
use App\Services\PostFileStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = Creator::factory()->create();
    $this->post = Post::factory()->for($this->creator)->create();
    $this->actingAs($this->creator->user);
});

it('uploads an image and returns signed urls', function () {
    $response = $this->postJson(route('creator.posts.files.store', $this->post), ['kind' => 'image', 'file' => jpegWithExif(800, 600)])
        ->assertCreated()
        ->assertJsonStructure(['id', 'kind', 'url', 'thumb_url', 'original_name', 'size']);

    $file = PostFile::findOrFail($response->json('id'));
    expect($file->post_id)->toBe($this->post->id)
        ->and($response->json('kind'))->toBe('image')
        ->and($response->json('url'))->toStartWith('https://signed.test/'.$file->path)
        ->and($response->json('thumb_url'))->toStartWith('https://signed.test/'.$file->thumb_path);
});

it('uploads an attachment', function () {
    $this->postJson(route('creator.posts.files.store', $this->post), [
        'kind' => 'attachment',
        'file' => UploadedFile::fake()->create('data.zip', 2048, 'application/zip'),
    ])->assertCreated()->assertJsonPath('original_name', 'data.zip');

    expect($this->post->attachments()->count())->toBe(1);
});

it('rejects invalid uploads', function (string $kind, Closure $file) {
    $this->postJson(route('creator.posts.files.store', $this->post), ['kind' => $kind, 'file' => $file()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
})->with([
    'pdf as image' => ['image', fn () => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')],
    'svg as image' => ['image', fn () => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')],
    'oversized image' => ['image', fn () => UploadedFile::fake()->image('a.jpg')->size(10241)],
    'oversized attachment' => ['attachment', fn () => UploadedFile::fake()->create('a.zip', 102401)],
]);

it('rejects an unknown kind', function () {
    $this->postJson(route('creator.posts.files.store', $this->post), ['kind' => 'video', 'file' => jpegWithExif(10, 10)])
        ->assertJsonValidationErrors('kind');
});

it('limits attachments per post', function () {
    PostFile::factory()->for($this->post)->attachment()->count(20)->create();

    $this->postJson(route('creator.posts.files.store', $this->post), ['kind' => 'attachment', 'file' => UploadedFile::fake()->create('a.zip', 1)])
        ->assertJsonValidationErrors('file');
});

it('limits images per post', function () {
    config(['fanbox.images_per_post' => 2]);
    PostFile::factory()->for($this->post)->count(2)->create();

    $this->postJson(route('creator.posts.files.store', $this->post), ['kind' => 'image', 'file' => jpegWithExif(10, 10)])
        ->assertJsonValidationErrors('file');
});

it('deletes a file of its own post', function () {
    $file = app(PostFileStorer::class)->store($this->post, jpegWithExif(10, 10), PostFileKind::Image);

    $this->deleteJson(route('creator.posts.files.destroy', [$this->post, $file]))->assertNoContent();

    expect(PostFile::find($file->id))->toBeNull();
    Storage::disk('private')->assertMissing($file->path);
});

it('returns 404 when deleting a file of another post', function () {
    $other = PostFile::factory()->for(Post::factory()->for($this->creator))->create();

    $this->deleteJson(route('creator.posts.files.destroy', [$this->post, $other]))->assertNotFound();
    expect(PostFile::find($other->id))->not->toBeNull();
});
