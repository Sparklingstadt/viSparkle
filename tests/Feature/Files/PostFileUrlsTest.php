<?php

use App\Models\PostFile;
use App\Services\PostFileUrls;

beforeEach(fn () => fakePrivateDisk());

it('issues a signed url valid for 5 minutes', function () {
    $this->freezeTime();
    $file = PostFile::factory()->create();

    expect(app(PostFileUrls::class)->url($file))
        ->toBe("https://signed.test/{$file->path}?expires=".now()->addMinutes(5)->getTimestamp());
});

it('signs the thumbnail, falling back to the original', function () {
    $image = PostFile::factory()->create();
    $attachment = PostFile::factory()->attachment()->create();

    expect(app(PostFileUrls::class)->thumbUrl($image))->toContain($image->thumb_path)
        ->and(app(PostFileUrls::class)->thumbUrl($attachment))->toContain($attachment->path);
});

it('forces download for attachments only', function () {
    $urls = app(PostFileUrls::class);

    expect($urls->url(PostFile::factory()->attachment()->create()))->toEndWith('&cd=1')
        ->and($urls->url(PostFile::factory()->create()))->not->toContain('&cd=1');
});
