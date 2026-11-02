<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateReportRequest;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Post;
use App\Models\Product;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $status = ReportStatus::tryFrom((string) $request->query('status'));

        $reports = Report::query()
            ->with(['reporter', 'resolver'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ReportStatus::Open->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Targets are polymorphic by enum, so load them in one query per type instead of per row.
        $posts = Post::query()
            ->whereIn('id', $reports->getCollection()->where('target_type', ReportTarget::Post)->pluck('target_id'))
            ->get()->keyBy('id');
        $creators = Creator::query()
            ->whereIn('id', $reports->getCollection()->where('target_type', ReportTarget::Creator)->pluck('target_id'))
            ->get()->keyBy('id');

        $products = Product::query()
            ->whereIn('id', $reports->getCollection()->where('target_type', ReportTarget::Product)->pluck('target_id'))
            ->get()->keyBy('id');

        $reports->through(fn (Report $report) => [
            'id' => $report->id,
            'reporter_name' => $report->reporter?->name,
            'target' => $this->describeTarget($report, $posts, $creators, $products),
            'reason_label' => $report->reason->label(),
            'body' => $report->body,
            'status' => $report->status->value,
            'created_at' => $report->created_at,
            'resolved_by_name' => $report->resolver?->name,
            'resolved_at' => $report->resolved_at,
            'resolution_note' => $report->resolution_note,
        ]);

        return Inertia::render('admin/reports', [
            'reports' => $reports,
            'status' => $status?->value,
        ]);
    }

    public function update(UpdateReportRequest $request, Report $report): RedirectResponse
    {
        $data = $request->validated();
        $status = ReportStatus::from($data['status']);

        DB::transaction(function () use ($request, $report, $status, $data) {
            $report->forceFill([
                'status' => $status,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
                'resolution_note' => $data['note'] ?? null,
            ])->save();

            AdminAction::record($request->user(), 'report.'.$status->value, $report, $data['note'] ?? null);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => '通報を更新しました。']);

        return back();
    }

    /**
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Creator>  $creators
     * @param  Collection<int, Product>  $products
     * @return array{label: string, url: string|null}
     */
    private function describeTarget(Report $report, $posts, $creators, $products): array
    {
        if ($report->target_type === ReportTarget::Post) {
            $post = $posts->get($report->target_id);

            return $post
                ? ['label' => $post->title, 'url' => route('admin.posts.show', $post)]
                : ['label' => '削除済み', 'url' => null];
        }

        if ($report->target_type === ReportTarget::Product) {
            $product = $products->get($report->target_id);

            return $product
                ? ['label' => $product->name, 'url' => route('admin.products.show', $product)]
                : ['label' => '削除済み', 'url' => null];
        }

        $creator = $creators->get($report->target_id);

        return $creator
            ? ['label' => $creator->display_name, 'url' => route('creators.show', $creator)]
            : ['label' => '削除済み', 'url' => null];
    }
}
