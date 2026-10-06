<?php

use App\Models\Creator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Storage::fake('public'));

it('shows the profile form', function () {
    $creator = Creator::factory()->create(['bio' => 'hello']);

    $this->actingAs($creator->user)
        ->get(route('creator.profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/profile')
            ->where('creator.slug', $creator->slug)
            ->where('creator.bio', 'hello'));
});

it('updates the profile', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)
        ->post(route('creator.profile.update'), ['display_name' => '新しい名前', 'bio' => '自己紹介'])
        ->assertRedirect(route('creator.profile.edit'));

    expect($creator->fresh()->display_name)->toBe('新しい名前')
        ->and($creator->fresh()->bio)->toBe('自己紹介');
});

it('does not allow changing the slug', function () {
    $creator = Creator::factory()->create(['slug' => 'alice']);

    $this->actingAs($creator->user)
        ->post(route('creator.profile.update'), ['slug' => 'bob', 'display_name' => 'A']);

    expect($creator->fresh()->slug)->toBe('alice');
});

it('validates bio length', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)
        ->post(route('creator.profile.update'), ['display_name' => 'A', 'bio' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('bio');
});

it('stores and replaces avatar and header images', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)->post(route('creator.profile.update'), [
        'display_name' => 'A',
        'avatar' => UploadedFile::fake()->image('a.jpg', 200, 200),
        'header' => UploadedFile::fake()->image('h.png', 1200, 400),
    ])->assertSessionHasNoErrors();

    $first = $creator->fresh();
    Storage::disk('public')->assertExists([$first->avatar_path, $first->header_path]);
    expect($first->avatar_path)->toStartWith("creators/{$creator->id}/avatar/")
        ->and($first->header_path)->toStartWith("creators/{$creator->id}/header/");

    $this->actingAs($creator->user)->post(route('creator.profile.update'), [
        'display_name' => 'A',
        'avatar' => UploadedFile::fake()->image('b.jpg', 200, 200),
    ]);

    $second = $creator->fresh();
    Storage::disk('public')->assertMissing($first->avatar_path);
    Storage::disk('public')->assertExists([$second->avatar_path, $first->header_path]);
});

it('rejects non-image avatars and oversized images', function (UploadedFile $file) {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)
        ->post(route('creator.profile.update'), ['display_name' => 'A', 'avatar' => $file])
        ->assertSessionHasErrors('avatar');
})->with([
    'pdf' => fn () => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    'oversized' => fn () => UploadedFile::fake()->image('a.jpg')->size(10241),
]);
