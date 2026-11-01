<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Services\Billing\CreatorClosure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClosureController extends Controller
{
    public function __construct(private readonly CreatorClosure $closure) {}

    public function store(Request $request): RedirectResponse
    {
        ['cancelled' => $cancelled, 'failed' => $failed] = $this->closure->close($request->user()->creator);

        $message = "活動を終了しました。{$cancelled}件の支援を期間の終わりで停止します。";

        if ($failed > 0) {
            $message .= "（{$failed}件は失敗しました。もう一度お試しください）";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->closure->reopen($request->user()->creator);

        Inertia::flash('toast', ['type' => 'success', 'message' => '活動を再開しました。プランの受付を再開してください。']);

        return back();
    }
}
