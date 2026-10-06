<?php

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostFile;
use App\Models\Support;
use Illuminate\Database\QueryException;

it('enforces one creator per user', function () {
    $creator = Creator::factory()->create();

    expect(fn () => Creator::factory()->for($creator->user)->create())->toThrow(QueryException::class);
});

it('enforces unique slugs', function () {
    Creator::factory()->create(['slug' => 'alice']);

    expect(fn () => Creator::factory()->create(['slug' => 'alice']))->toThrow(QueryException::class);
});

it('enforces unique plan price per creator', function () {
    $creator = Creator::factory()->create();
    Plan::factory()->for($creator)->price(500)->create();

    expect(fn () => Plan::factory()->for($creator)->price(500)->create())->toThrow(QueryException::class);
});

it('allows the same price for different creators', function () {
    Plan::factory()->price(500)->create();
    Plan::factory()->price(500)->create();

    expect(Plan::count())->toBe(2);
});

it('nulls support plan_id when plan is deleted', function () {
    $support = Support::factory()->ended()->create();

    $support->plan->delete();

    expect($support->fresh()->plan_id)->toBeNull()
        ->and($support->fresh()->price)->toBeGreaterThan(0);
});

it('scopes published posts newest first', function () {
    $creator = Creator::factory()->create();
    $old = Post::factory()->for($creator)->published()->create(['published_at' => now()->subDay()]);
    $new = Post::factory()->for($creator)->published()->create(['published_at' => now()]);
    Post::factory()->for($creator)->draft()->create();

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

it('copies plan price and creator into support factory', function () {
    $plan = Plan::factory()->price(1000)->create();

    $support = Support::factory()->active()->forPlan($plan)->create();

    expect($support->price)->toBe(1000)
        ->and($support->creator_id)->toBe($plan->creator_id)
        ->and($support->status)->toBe(SupportStatus::Active)
        ->and(Support::active()->count())->toBe(1);
});

it('relates users, creators, plans, posts and files', function () {
    $creator = Creator::factory()->create();
    $plan = Plan::factory()->for($creator)->create();
    $post = Post::factory()->for($creator)->create();
    $image = PostFile::factory()->for($post)->create(['kind' => PostFileKind::Image]);
    $attachment = PostFile::factory()->for($post)->attachment()->create();
    $post->update(['cover_file_id' => $image->id]);
    $support = Support::factory()->active()->forPlan($plan)->create();

    expect($creator->user->creator->is($creator))->toBeTrue()
        ->and($creator->plans->first()->is($plan))->toBeTrue()
        ->and($creator->posts->first()->is($post))->toBeTrue()
        ->and($post->images->pluck('id')->all())->toBe([$image->id])
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

it('routes creators by slug', function () {
    expect((new Creator)->getRouteKeyName())->toBe('slug');
});
