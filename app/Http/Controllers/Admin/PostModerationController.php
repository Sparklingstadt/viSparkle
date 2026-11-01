<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePostVisibilityRequest;
use App\Models\AdminAction;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PostModerationController extends Controller
{
    public function show(Post $post): Response
    {
        $post->load('creator');

        $reports = Report::query()
            ->with('reporter')
            ->where('target_type', ReportTarget::Post)
            ->where('target_id', $post->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Report $report) => [
                'id' => $report->id,
                'reporter_name' => $report->reporter?->name,
                'reason_label' => $report->reason->label(),
                'body' => $report->body,
                'status' => $report->status->value,
                'created_at' => $report->created_at,
            ]);

        return Inertia::render('admin/post', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'status' => $post->status,
                'required_price' => $post->required_price,
                'published_at' => $post->published_at,
                'hidden' => $post->isHidden(),
                'hidden_at' => $post->hidden_at,
                'hidden_reason' => $post->hidden_reason,
                'creator' => ['name' => $post->creator->display_name, 'slug' => $post->creator->slug],
                'url' => route('posts.show', [$post->creator, $post]),
            ],
            'reports' => $reports,
        ]);
    }

    public function update(UpdatePostVisibilityRequest $request, Post $post): RedirectResponse
    {
        $hidden = $request->boolean('hidden');
        $reason = $hidden ? (string) $request->validated('reason') : null;

        DB::transaction(function () use ($request, $post, $hidden, $reason) {
            $post->forceFill([
                'hidden_at' => $hidden ? ($post->hidden_at ?? now()) : null,
                'hidden_reason' => $reason,
            ])->save();

            AdminAction::record($request->user(), $hidden ? 'post.hidden' : 'post.unhidden', $post, $reason);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $hidden ? '投稿を非公開にしました。' : '投稿を公開に戻しました。']);

        return back();
    }
}
