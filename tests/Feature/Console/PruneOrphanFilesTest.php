<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\PostFile;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('public');
});

function putFile(string $disk, string $path, int $ageHours): string
{
    Storage::disk($disk)->put($path, 'x');
    touch(Storage::disk($disk)->path($path), now()->subHours($ageHours)->getTimestamp());

    return $path;
}

it('deletes old unreferenced files and keeps referenced or recent ones', function () {
    $file = PostFile::factory()->create();
    $referenced = putFile('private', $file->path, 48);
    $referencedThumb = putFile('private', $file->thumb_path, 48);
    $orphan = putFile('private', 'creators/1/posts/1/orphan.jpg', 48);
    $fresh = putFile('private', 'creators/1/posts/1/uploading.jpg', 1);

    $creator = Creator::factory()->create(['avatar_path' => 'creators/9/avatar/a.jpg']);
    $plan = Plan::factory()->for($creator)->create(['cover_path' => 'creators/9/plans/c.jpg']);
    $avatar = putFile('public', $creator->avatar_path, 48);
    $cover = putFile('public', $plan->cover_path, 48);
    $oldAvatar = putFile('public', 'creators/9/avatar/old.jpg', 48);

    $this->artisan('fanbox:prune-orphan-files')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Storage::disk('private')->assertExists([$referenced, $referencedThumb, $fresh]);
    Storage::disk('private')->assertMissing($orphan);
    Storage::disk('public')->assertExists([$avatar, $cover]);
    Storage::disk('public')->assertMissing($oldAvatar);
});

it('only reports in dry-run mode', function () {
    $orphan = putFile('private', 'creators/1/posts/1/orphan.jpg', 48);

    $this->artisan('fanbox:prune-orphan-files', ['--dry-run' => true])->assertSuccessful();

    Storage::disk('private')->assertExists($orphan);
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'fanbox:prune-orphan-files'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});
