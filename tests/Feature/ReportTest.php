<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Models\Creator;
use App\Models\Post;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;

beforeEach(function () {
    $this->fan = User::factory()->create();
    $this->post = Post::factory()->published()->create();
});

it('creates a report on a post', function () {
    $this->actingAs($this->fan)->post(route('reports.store'), [
        'target_type' => 'post',
        'target_id' => $this->post->id,
        'reason' => 'spam',
        'body' => '宣伝ばかりです',
    ])->assertRedirect();

    $report = Report::sole();
    expect($report->reporter_id)->toBe($this->fan->id)
        ->and($report->target_type)->toBe(ReportTarget::Post)
        ->and($report->target_id)->toBe($this->post->id)
        ->and($report->reason)->toBe(ReportReason::Spam)
        ->and($report->body)->toBe('宣伝ばかりです')
        ->and($report->status)->toBe(ReportStatus::Open);
});

it('creates a report on a creator', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($this->fan)->post(route('reports.store'), [
        'target_type' => 'creator',
        'target_id' => $creator->id,
        'reason' => 'rights',
    ])->assertRedirect();

    expect(Report::sole()->target_type)->toBe(ReportTarget::Creator);
});

it('updates the open report instead of creating a duplicate', function () {
    $payload = ['target_type' => 'post', 'target_id' => $this->post->id, 'reason' => 'spam'];

    $this->actingAs($this->fan)->post(route('reports.store'), $payload)->assertRedirect();
    $this->actingAs($this->fan)->post(route('reports.store'), [...$payload, 'reason' => 'adult', 'body' => '追記'])->assertRedirect();

    $report = Report::sole();
    expect($report->reason)->toBe(ReportReason::Adult)->and($report->body)->toBe('追記');
});

it('creates a new report when the earlier one was handled', function () {
    Report::factory()->create([
        'reporter_id' => $this->fan->id,
        'target_type' => ReportTarget::Post,
        'target_id' => $this->post->id,
        'status' => ReportStatus::Resolved,
    ]);

    $this->actingAs($this->fan)->post(route('reports.store'), [
        'target_type' => 'post', 'target_id' => $this->post->id, 'reason' => 'spam',
    ])->assertRedirect();

    expect(Report::count())->toBe(2);
});

it('does not let users report their own page or posts', function () {
    $own = Post::factory()->published()->create();

    $this->actingAs($own->creator->user)->post(route('reports.store'), [
        'target_type' => 'post', 'target_id' => $own->id, 'reason' => 'spam',
    ])->assertSessionHasErrors(['target_id' => '自分の投稿やページは通報できません。']);

    $this->actingAs($own->creator->user)->post(route('reports.store'), [
        'target_type' => 'creator', 'target_id' => $own->creator->id, 'reason' => 'spam',
    ])->assertSessionHasErrors(['target_id' => '自分の投稿やページは通報できません。']);

    expect(Report::count())->toBe(0);
});

it('rejects missing, draft and hidden targets', function () {
    $draft = Post::factory()->draft()->create();
    $hidden = Post::factory()->published()->create();
    $hidden->forceFill(['hidden_at' => now()])->save();

    foreach ([
        ['post', 999999],
        ['creator', 999999],
        ['post', $draft->id],
        ['post', $hidden->id],
    ] as [$type, $id]) {
        $this->actingAs($this->fan)->post(route('reports.store'), [
            'target_type' => $type, 'target_id' => $id, 'reason' => 'spam',
        ])->assertSessionHasErrors('target_id');
    }

    expect(Report::count())->toBe(0);
});

it('validates the reason, type and body length', function () {
    $this->actingAs($this->fan)->post(route('reports.store'), [
        'target_type' => 'nope', 'target_id' => $this->post->id, 'reason' => 'bogus', 'body' => str_repeat('あ', 1001),
    ])->assertSessionHasErrors(['target_type', 'reason', 'body']);
});

it('redirects guests to login', function () {
    $this->post(route('reports.store'), [
        'target_type' => 'post', 'target_id' => $this->post->id, 'reason' => 'spam',
    ])->assertRedirect(route('login'));
});

it('throttles at the 11th report in an hour', function () {
    $posts = Post::factory()->published()->count(11)->create();

    foreach ($posts as $i => $post) {
        $response = $this->actingAs($this->fan)->post(route('reports.store'), [
            'target_type' => 'post', 'target_id' => $post->id, 'reason' => 'spam',
        ]);

        $i < 10 ? $response->assertRedirect() : $response->assertStatus(429);
    }
});

it('passes the reason options to the public pages', function () {
    $this->get(route('posts.show', [$this->post->creator, $this->post]))
        ->assertInertia(fn ($page) => $page->has('reportReasons', 5)->where('reportReasons.0.value', 'inappropriate'));

    $this->get(route('creators.show', $this->post->creator))
        ->assertInertia(fn ($page) => $page->has('reportReasons', 5));
});

it('accepts a report on a product', function () {
    $product = Product::factory()->create();

    $this->actingAs($this->fan)->post(route('reports.store'), [
        'target_type' => 'product',
        'target_id' => $product->id,
        'reason' => 'rights',
    ])->assertRedirect();

    $report = Report::sole();
    expect($report->target_type)->toBe(ReportTarget::Product)
        ->and($report->target_id)->toBe($product->id);
});

it('rejects a report on a hidden product or on your own product', function () {
    $hidden = Product::factory()->create(['hidden_at' => now(), 'hidden_reason' => 'x']);
    $mine = Product::factory()->for(Creator::factory()->create(['user_id' => $this->fan->id]))->create();

    foreach ([$hidden, $mine] as $product) {
        $this->actingAs($this->fan)->post(route('reports.store'), [
            'target_type' => 'product',
            'target_id' => $product->id,
            'reason' => 'rights',
        ])->assertSessionHasErrors('target_id');
    }

    expect(Report::count())->toBe(0);
});
