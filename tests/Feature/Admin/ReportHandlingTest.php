<?php

use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists open reports first, then newest', function () {
    $resolvedNew = Report::factory()->create(['status' => ReportStatus::Resolved, 'created_at' => now()]);
    $openOld = Report::factory()->create(['created_at' => now()->subDays(3)]);
    $openNew = Report::factory()->create(['created_at' => now()->subDay()]);

    $this->actingAs($this->admin)->get(route('admin.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/reports')
            ->where('reports.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$openNew->id, $openOld->id, $resolvedNew->id]));
});

it('filters by status', function () {
    Report::factory()->create();
    $dismissed = Report::factory()->create(['status' => ReportStatus::Dismissed]);

    $this->actingAs($this->admin)->get(route('admin.reports.index', ['status' => 'dismissed']))
        ->assertInertia(fn ($page) => $page
            ->where('reports.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$dismissed->id])
            ->where('status', 'dismissed'));
});

it('describes post and creator targets, and deleted ones', function () {
    $post = Post::factory()->published()->create(['title' => 'こんにちは']);
    $creator = Creator::factory()->create();
    Report::factory()->create(['target_id' => $post->id, 'body' => '詳細']);
    Report::factory()->create(['target_type' => ReportTarget::Creator, 'target_id' => $creator->id]);
    Report::factory()->create(['target_id' => 999999]);

    $this->actingAs($this->admin)->get(route('admin.reports.index'))
        ->assertInertia(fn ($page) => $page
            ->has('reports.data', 3)
            ->where('reports.data.0.target.label', '削除済み')
            ->where('reports.data.0.target.url', null)
            ->where('reports.data.1.target.url', route('creators.show', $creator))
            ->where('reports.data.2.target.label', 'こんにちは')
            ->where('reports.data.2.target.url', route('admin.posts.show', $post))
            ->where('reports.data.2.reason_label', '不適切な内容')
            ->where('reports.data.2.body', '詳細'));
});

it('resolves a report and records the action', function () {
    $report = Report::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.reports.update', $report), ['status' => 'resolved', 'note' => '対応しました'])
        ->assertRedirect();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->resolved_by)->toBe($this->admin->id)
        ->and($report->resolved_at)->not->toBeNull()
        ->and($report->resolution_note)->toBe('対応しました');

    $action = AdminAction::sole();
    expect($action->action)->toBe('report.resolved')
        ->and($action->target_type)->toBe('report')
        ->and($action->target_id)->toBe($report->id)
        ->and($action->admin_id)->toBe($this->admin->id)
        ->and($action->note)->toBe('対応しました');
});

it('dismisses a report without a note', function () {
    $report = Report::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.reports.update', $report), ['status' => 'dismissed'])->assertRedirect();

    expect($report->fresh()->status)->toBe(ReportStatus::Dismissed)
        ->and(AdminAction::sole()->action)->toBe('report.dismissed');
});

it('rejects invalid status and over-long notes', function () {
    $report = Report::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.reports.update', $report), ['status' => 'open'])->assertSessionHasErrors('status');
    $this->actingAs($this->admin)->patch(route('admin.reports.update', $report), ['status' => 'resolved', 'note' => str_repeat('あ', 1001)])->assertSessionHasErrors('note');

    expect($report->fresh()->status)->toBe(ReportStatus::Open)->and(AdminAction::count())->toBe(0);
});
