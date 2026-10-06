<?php

use App\Enums\PostFileKind;
use App\Models\Post;
use App\Models\PostFile;
use App\Services\PostFileStorer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakePrivateDisk());

it('strips exif and scales down jpeg images', function () {
    $upload = jpegWithExif();
    expect(file_get_contents($upload->getRealPath()))->toContain('Exif');
    $post = Post::factory()->create();

    $file = app(PostFileStorer::class)->store($post, $upload, PostFileKind::Image);

    $disk = Storage::disk('private');
    expect($disk->get($file->path))->not->toContain('Exif')
        ->and($disk->get($file->thumb_path))->not->toContain('Exif')
        ->and($file->width)->toBe(2560)
        ->and($file->height)->toBe(1707)
        ->and($file->kind)->toBe(PostFileKind::Image)
        ->and($file->original_name)->toBe('photo.jpg')
        ->and($file->mime)->toBe('image/jpeg')
        ->and($file->size)->toBe(strlen($disk->get($file->path)))
        ->and($file->path)->toMatch("#^creators/{$post->creator_id}/posts/{$post->id}/[0-9a-z]{26}\\.jpg$#")
        ->and($file->thumb_path)->toBe(str_replace('.jpg', '_thumb.jpg', $file->path));

    [$thumbWidth] = getimagesizefromstring($disk->get($file->thumb_path));
    expect($thumbWidth)->toBe(640);
});

it('keeps small images at their size', function () {
    $file = app(PostFileStorer::class)->store(Post::factory()->create(), jpegWithExif(800, 600), PostFileKind::Image);

    expect($file->width)->toBe(800)->and($file->height)->toBe(600);
});

it('stores gif originals untouched with a still thumbnail', function () {
    $upload = gifUpload();
    $original = file_get_contents($upload->getRealPath());

    $file = app(PostFileStorer::class)->store(Post::factory()->create(), $upload, PostFileKind::Image);

    expect(Storage::disk('private')->get($file->path))->toBe($original)
        ->and($file->path)->toEndWith('.gif')
        ->and($file->width)->toBe(1200);
    Storage::disk('private')->assertExists($file->thumb_path);
});

it('stores attachments as-is without a thumbnail', function () {
    $upload = UploadedFile::fake()->createWithContent('資料 (1).zip', 'PK-zip-bytes');

    $file = app(PostFileStorer::class)->store(Post::factory()->create(), $upload, PostFileKind::Attachment);

    expect(Storage::disk('private')->get($file->path))->toBe('PK-zip-bytes')
        ->and($file->thumb_path)->toBeNull()
        ->and($file->original_name)->toBe('資料 (1).zip')
        ->and($file->path)->toEndWith('.zip')
        ->and($file->size)->toBe(12);
});

it('rolls back the row and removes written files when storage fails', function () {
    $post = Post::factory()->create();
    $real = Storage::disk('private');
    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('put')->once()->andReturnUsing(fn ($path, $contents) => $real->put($path, $contents));
    $failing->shouldReceive('put')->andThrow(new RuntimeException('disk full'));
    $failing->shouldReceive('delete')->andReturnUsing(fn ($paths) => $real->delete($paths));
    Storage::set('private', $failing);

    expect(fn () => app(PostFileStorer::class)->store($post, jpegWithExif(), PostFileKind::Image))
        ->toThrow(RuntimeException::class);

    expect(PostFile::count())->toBe(0)->and($real->allFiles())->toBe([]);
});

it('deletes stored files with the row', function () {
    $file = app(PostFileStorer::class)->store(Post::factory()->create(), jpegWithExif(), PostFileKind::Image);

    app(PostFileStorer::class)->delete($file);

    expect(PostFile::count())->toBe(0);
    Storage::disk('private')->assertMissing([$file->path, $file->thumb_path]);
});
