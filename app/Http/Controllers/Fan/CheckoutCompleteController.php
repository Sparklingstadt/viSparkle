<?php

namespace App\Http\Controllers\Fan;

use App\Billing\BillingException;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Services\Billing\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Where Stripe Checkout sends the fan back to; the webhook may not have arrived yet, so sync here.
 */
class CheckoutCompleteController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        abort_unless(is_string($sessionId) && $sessionId !== '', 404);

        try {
            $session = $checkout->ownSession($request->user(), $sessionId);
        } catch (BillingException $e) {
            report($e);

            return $this->unreachable(to_route('supporting.index'));
        }

        $creator = Creator::query()->findOrFail((int) ($session->metadata['creator_id'] ?? 0));
        $target = to_route('creators.show', $creator);

        try {
            $support = $checkout->finish($session);
        } catch (BillingException $e) {
            report($e);

            return $this->unreachable($target);
        }

        if ($support === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'お支払いが完了していません。']);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => "{$creator->display_name} さんの支援を開始しました。"]);
        }

        return $target;
    }

    private function unreachable(RedirectResponse $to): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

        return $to;
    }
}
