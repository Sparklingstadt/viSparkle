<?php

use App\Enums\PostFileKind;
use App\Enums\ReportReason;
use App\Enums\ReportTarget;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Report;
use App\Models\Support;
use App\Models\User;
use App\Services\Entitlement;
use App\Services\PostFileStorer;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePrivateDisk();
    $this->admin = User::factory()->admin()->create();
    $this->creator = Creator::factory()->create();
});

it('hides a post with a reason and records it', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->actingAs($this->admin)->patch(route('admin.posts.update', $post), ['hidden' => true, 'reason' => '著作権侵害'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '投稿を非公開にしました。']);

    $post->refresh();
    expect($post->isHidden())->toBeTrue()
        ->and($post->hidden_reason)->toBe('著作権侵害')
        ->and(AdminAction::sole())->action->toBe('post.hidden')->note->toBe('著作権侵害');
});

it('requires a reason to hide', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->actingAs($this->admin)->patch(route('admin.posts.update', $post), ['hidden' => true, 'reason' => ''])
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->admin)->patch(route('admin.posts.update', $post), ['hidden' => true, 'reason' => str_repeat('あ', 501)])
        ->assertSessionHasErrors('reason');

    expect($post->fresh()->isHidden())->toBeFalse();
});

it('unhides a post and restores it everywhere', function () {
    $post = Post::factory()->for($this->creator)->published()->hidden()->create();

    $this->actingAs($this->admin)->patch(route('admin.posts.update', $post), ['hidden' => false])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '投稿を公開に戻しました。']);

    $post->refresh();
    expect($post->hidden_at)->toBeNull()
        ->and($post->hidden_reason)->toBeNull()
        ->and(AdminAction::sole()->action)->toBe('post.unhidden');

    $this->get(route('posts.show', [$this->creator, $post]))->assertOk();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('posts', 1));
});

it('is admin only', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.posts.show', $post))->assertNotFound();
    $this->actingAs(User::factory()->create())->patch(route('admin.posts.update', $post), ['hidden' => true, 'reason' => 'x'])->assertNotFound();
});

it('shows the post with its reports', function () {
    $post = Post::factory()->for($this->creator)->published()->hidden('スパム')->create();
    Report::factory()->create(['target_type' => ReportTarget::Post, 'target_id' => $post->id, 'reason' => ReportReason::Spam, 'body' => '宣伝ばかり']);
    Report::factory()->create(['target_type' => ReportTarget::Creator, 'target_id' => $this->creator->id]);

    $this->actingAs($this->admin)->get(route('admin.posts.show', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/post')
            ->where('post.id', $post->id)
            ->where('post.title', $post->title)
            ->where('post.hidden', true)
            ->where('post.hidden_reason', 'スパム')
            ->where('post.creator.slug', $this->creator->slug)
            ->has('reports', 1)
            ->where('reports.0.body', '宣伝ばかり'));
});

it('links reported posts to the admin post page', function () {
    $post = Post::factory()->for($this->creator)->published()->create();
    Report::factory()->create(['target_type' => ReportTarget::Post, 'target_id' => $post->id]);

    $this->actingAs($this->admin)->get(route('admin.reports.index'))
        ->assertInertia(fn (Assert $page) => $page->where('reports.data.0.target.url', route('admin.posts.show', $post)));
});

it('treats a hidden post as missing for everyone but its author', function () {
    $plan = Plan::factory()->for($this->creator)->price(500)->create();
    $post = Post::factory()->for($this->creator)->published()->hidden('規約違反')->create();
    $paid = Post::factory()->for($this->creator)->published()->requiring(500)->hidden()->create();
    $file = app(PostFileStorer::class)->store($post, UploadedFile::fake()->createWithContent('a.zip', 'zip'), PostFileKind::Attachment);
    $fan = User::factory()->create();
    Support::factory()->for($fan)->forPlan($plan)->active()->create();

    // Post page and download.
    $this->get(route('posts.show', [$this->creator, $post]))->assertNotFound();
    $this->actingAs($fan)->get(route('posts.show', [$this->creator, $paid]))->assertNotFound();
    $this->actingAs($fan)->get(route('posts.files.show', [$this->creator, $post, $file]))->assertNotFound();

    // Lists.
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('posts', 0));
    $this->get(route('creators.show', $this->creator))->assertInertia(fn (Assert $page) => $page->has('posts.data', 0));
    $this->actingAs($fan)->get(route('feed'))->assertInertia(fn (Assert $page) => $page->has('posts.data', 0));

    // Entitlement.
    expect(Entitlement::for($fan)->canView($paid))->toBeFalse()
        ->and(Entitlement::for($fan)->canView($post))->toBeFalse()
        ->and(Entitlement::for($this->creator->user)->canView($paid))->toBeTrue();

    // The author still sees it, with the notice.
    $this->actingAs($this->creator->user)->get(route('posts.show', [$this->creator, $post]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hiddenReason', '規約違反'));
    $this->actingAs($this->creator->user)->get(route('posts.files.show', [$this->creator, $post, $file]))->assertRedirect();
});

it('does not tell others why a post is hidden', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->get(route('posts.show', [$this->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page->where('hiddenReason', null));
});

it('shows the hidden notice in the editor and the post list', function () {
    $post = Post::factory()->for($this->creator)->published()->hidden('規約違反')->create();
    $this->actingAs($this->creator->user);

    $this->get(route('creator.posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page->where('post.hidden', true)->where('post.hidden_reason', '規約違反'));
    $this->get(route('creator.posts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('posts.data.0.hidden', true));
});

it('keeps the post hidden when the author republishes it', function () {
    $post = Post::factory()->for($this->creator)->published()->hidden()->create();

    $this->actingAs($this->creator->user)->put(route('creator.posts.update', $post), [
        'action' => 'publish',
        'title' => 'タイトル',
        'excerpt' => '',
        'body' => ttDoc(ttParagraph(ttText('本文'))),
        'required_price' => 0,
        'cover_file_id' => null,
    ])->assertRedirect();

    expect($post->fresh()->isHidden())->toBeTrue();
});
