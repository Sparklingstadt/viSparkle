<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Http\Requests\StoreReportRequest;
use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function store(StoreReportRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // One open report per reporter and target: reporting again updates it.
        $report = Report::query()->firstOrNew([
            'reporter_id' => $request->user()->id,
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'status' => ReportStatus::Open,
        ]);
        $report->fill(['reason' => $data['reason'], 'body' => $data['body'] ?? null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => '通報を受け付けました。ご協力ありがとうございます。']);

        return back();
    }
}
