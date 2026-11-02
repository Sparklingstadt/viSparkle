<?php

use App\Models\Creator;
use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = Creator::factory()->payable()->create();
    $this->work = Product::factory()->digital()->unpublished()->for($this->creator)->create();
    $this->actingAs($this->creator->user);
});

it('uploads files to a digital work', function () {
    $this->post(route('creator.products.files.store', $this->work), ['file' => UploadedFile::fake()->create('画集.zip', 100, 'application/zip')])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $file = $this->work->files()->sole();
    expect($file->original_name)->toBe('画集.zip');
    Storage::disk('private')->assertExists($file->path);
});

it('rejects the eleventh file', function () {
    ProductFile::factory()->count(10)->for($this->work)->create();

    $this->post(route('creator.products.files.store', $this->work), ['file' => UploadedFile::fake()->create('a.zip', 1)])
        ->assertSessionHasErrors('file');

    expect($this->work->files()->count())->toBe(10);
});

it('rejects a file over the size limit', function () {
    $this->post(route('creator.products.files.store', $this->work), ['file' => UploadedFile::fake()->create('big.zip', 102401)])
        ->assertSessionHasErrors('file');

    expect($this->work->files()->count())->toBe(0);
});

it('rejects files on a physical product', function () {
    $goods = Product::factory()->for($this->creator)->create();

    $this->post(route('creator.products.files.store', $goods), ['file' => UploadedFile::fake()->create('a.zip', 1)])->assertNotFound();
});

it('reorders files', function () {
    [$a, $b] = ProductFile::factory()->count(2)->for($this->work)->sequence(['sort_order' => 1], ['sort_order' => 2])->create();

    $this->put(route('creator.products.files.reorder', $this->work), ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();

    expect($this->work->files()->pluck('id')->all())->toBe([$b->id, $a->id]);
});

it('only reorders with exactly the work\'s own file ids', function () {
    $a = ProductFile::factory()->for($this->work)->create();
    $other = ProductFile::factory()->create();

    $this->put(route('creator.products.files.reorder', $this->work), ['ids' => [$a->id, $other->id]])->assertSessionHasErrors('ids');
});

it('refuses to delete the last file of a published work', function () {
    $this->work->update(['is_published' => true]);
    $file = ProductFile::factory()->for($this->work)->create();
    Storage::disk('private')->put($file->path, 'x');

    $this->delete(route('creator.products.files.destroy', [$this->work, $file]))
        ->assertSessionHasErrors(['file' => '公開中の作品からすべてのファイルを消すことはできません。']);

    expect($file->fresh())->not->toBeNull();
    Storage::disk('private')->assertExists($file->path);
});

it('deletes a file of an unpublished work', function () {
    $file = ProductFile::factory()->for($this->work)->create();
    Storage::disk('private')->put($file->path, 'x');

    $this->delete(route('creator.products.files.destroy', [$this->work, $file]))->assertSessionHasNoErrors();

    expect($file->fresh())->toBeNull();
    Storage::disk('private')->assertMissing($file->path);
});

it('lets the creator download their own file', function () {
    $file = ProductFile::factory()->for($this->work)->create();

    $response = $this->get(route('creator.products.files.download', [$this->work, $file]))->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith("https://signed.test/{$file->path}")->toContain('&cd=1');
});

it('hides other creators\' files', function () {
    $theirs = Product::factory()->digital()->create();
    $file = ProductFile::factory()->for($theirs)->create();

    $this->post(route('creator.products.files.store', $theirs), ['file' => UploadedFile::fake()->create('a.zip', 1)])->assertForbidden();
    $this->delete(route('creator.products.files.destroy', [$theirs, $file]))->assertForbidden();
    $this->get(route('creator.products.files.download', [$theirs, $file]))->assertForbidden();
    $this->put(route('creator.products.files.reorder', $theirs), ['ids' => [$file->id]])->assertForbidden();

    // Another work's file through one's own work is not found.
    $this->delete(route('creator.products.files.destroy', [$this->work, $file]))->assertNotFound();
    $this->get(route('creator.products.files.download', [$this->work, $file]))->assertNotFound();
    expect($file->fresh())->not->toBeNull();
});
