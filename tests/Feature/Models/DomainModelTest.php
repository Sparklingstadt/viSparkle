<?php

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use App\Enums\SupportStatus;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostFile;
use App\Models\Support;
use Illuminate\Database\QueryException;

it('enforces unique plan prices', function () {
    Plan::factory()->price(500)->create();

    expect(fn () => Plan::factory()->price(500)->create())->toThrow(QueryException::class);
});

it('nulls support plan_id when plan is deleted', function () {
    $support = Support::factory()->ended()->create();

    $support->plan->delete();

    expect($support->fresh()->plan_id)->toBeNull()
        ->and($support->fresh()->price)->toBeGreaterThan(0);
});

it('scopes published posts newest first', function () {
    $old = Post::factory()->published()->create(['published_at' => now()->subDay()]);
    $new = Post::factory()->published()->create(['published_at' => now()]);
    Post::factory()->draft()->create();

    expect(Post::published()->pluck('id')->all())->toBe([$new->id, $old->id]);
});

it('casts post attributes', function () {
    $post = Post::factory()->published()->requiring(500)->create(['body' => ['type' => 'doc']]);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($post->fresh()->body)->toBe(['type' => 'doc'])
        ->and($post->fresh()->required_price)->toBe(500)
        ->and($post->isPublished())->toBeTrue()
        ->and(Post::factory()->draft()->create()->isPublished())->toBeFalse();
});

it('copies the plan price into the support factory', function () {
    $plan = Plan::factory()->price(1000)->create();

    $support = Support::factory()->active()->forPlan($plan)->create();

    expect($support->price)->toBe(1000)
        ->and($support->status)->toBe(SupportStatus::Active)
        ->and(Support::active()->count())->toBe(1);
});

it('relates users, plans, supports, posts and files', function () {
    $plan = Plan::factory()->create();
    $post = Post::factory()->create();
    $image = PostFile::factory()->for($post)->create(['kind' => PostFileKind::Image]);
    $attachment = PostFile::factory()->for($post)->attachment()->create();
    $post->update(['cover_file_id' => $image->id]);
    $support = Support::factory()->active()->forPlan($plan)->create();

    expect($post->images->pluck('id')->all())->toBe([$image->id])
        ->and($post->attachments->pluck('id')->all())->toBe([$attachment->id])
        ->and($post->cover->is($image))->toBeTrue()
        ->and($support->user->supports->first()->is($support))->toBeTrue()
        ->and($plan->activeSupports()->count())->toBe(1);
});

it('deletes files when post is deleted', function () {
    $file = PostFile::factory()->create();

    $file->post->delete();

    expect(PostFile::count())->toBe(0);
});

it('nulls cover when cover file is deleted', function () {
    $file = PostFile::factory()->create();
    $file->post->update(['cover_file_id' => $file->id]);

    $file->delete();

    expect($file->post->fresh()->cover_file_id)->toBeNull();
});
