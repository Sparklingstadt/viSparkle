<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Http\Requests\StoreReportRequest;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function store(StoreReportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // One open report per reporter and target: reporting again updates it. The reporter's row is locked so
        // concurrent submits queue up instead of both inserting.
        DB::transaction(function () use ($request, $data) {
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            $report = Report::query()->firstOrNew([
                'reporter_id' => $request->user()->id,
                'target_type' => $data['target_type'],
                'target_id' => $data['target_id'],
                'status' => ReportStatus::Open,
            ]);
            $report->forceFill(['status' => ReportStatus::Open])
                ->fill(['reason' => $data['reason'], 'body' => $data['body'] ?? null])
                ->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => '通報を受け付けました。ご協力ありがとうございます。']);

        return back();
    }
}
