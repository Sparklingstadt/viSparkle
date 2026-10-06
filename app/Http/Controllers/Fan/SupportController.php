<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Services\SupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupportController extends Controller
{
    public function __construct(private readonly SupportService $supports) {}

    public function store(Request $request, Creator $creator, Plan $plan): RedirectResponse
    {
        abort_unless($plan->creator_id === $creator->id, 404);

        $this->supports->start($request->user(), $plan);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$creator->display_name} さんの支援を開始しました。"]);

        return to_route('creators.show', $creator);
    }

    public function destroy(Request $request, Support $support): RedirectResponse
    {
        abort_unless($support->user_id === $request->user()->id, 404);

        $this->supports->end($support);

        Inertia::flash('toast', ['type' => 'success', 'message' => '支援を停止しました。']);

        return back();
    }
}
