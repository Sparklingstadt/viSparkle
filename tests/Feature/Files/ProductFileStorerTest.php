<?php

use App\Models\Product;
use App\Models\ProductFile;
use App\Services\Shop\ProductFileStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakePrivateDisk());

it('stores a work file on the private disk', function () {
    $work = Product::factory()->digital()->create();

    $file = app(ProductFileStorer::class)->store($work, UploadedFile::fake()->create('画集 vol.1.zip', 2048, 'application/zip'));

    expect($file->path)->toStartWith("creators/{$work->creator_id}/products/{$work->id}/")->toEndWith('.zip')
        ->and($file->original_name)->toBe('画集 vol.1.zip')
        ->and($file->size)->toBe(2048 * 1024)
        ->and($file->sort_order)->toBe(1);
    Storage::disk('private')->assertExists($file->path);
});

it('puts a new file after the existing ones', function () {
    $work = Product::factory()->digital()->create();
    ProductFile::factory()->for($work)->create(['sort_order' => 4]);

    $file = app(ProductFileStorer::class)->store($work, UploadedFile::fake()->create('b.pdf', 10));

    expect($file->sort_order)->toBe(5);
});

it('falls back to bin for an odd extension', function () {
    $work = Product::factory()->digital()->create();

    $file = app(ProductFileStorer::class)->store($work, UploadedFile::fake()->create('a.tar.gz!', 10));

    expect($file->path)->toEndWith('.bin');
});

it('deletes the row and the stored file', function () {
    $work = Product::factory()->digital()->create();
    $file = app(ProductFileStorer::class)->store($work, UploadedFile::fake()->create('a.pdf', 10));

    app(ProductFileStorer::class)->delete($file);

    expect(ProductFile::query()->count())->toBe(0);
    Storage::disk('private')->assertMissing($file->path);
});
