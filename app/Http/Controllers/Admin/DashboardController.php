<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Report;
use App\Models\Support;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $since = now()->subDays(30);

        return Inertia::render('admin/dashboard', [
            'openReports' => Report::query()->where('status', ReportStatus::Open)->count(),
            'sales30d' => (int) Payment::query()->where('paid_at', '>=', $since)->sum('amount'),
            'refunds30d' => (int) Payment::query()->where('refunded_at', '>=', $since)->sum('refunded_amount'),
            'activeSupports' => Support::query()->active()->count(),
        ]);
    }
}
