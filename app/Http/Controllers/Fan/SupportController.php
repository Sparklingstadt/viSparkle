<?php

namespace App\Http\Controllers\Fan;

use App\Billing\BillingException;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Services\Billing\CheckoutService;
use App\Services\SupportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class SupportController extends Controller
{
    public function __construct(
        private readonly SupportService $supports,
        private readonly CheckoutService $checkout,
    ) {}

    /**
     * Start a Checkout session and send the fan there; the support is created when they come back (or by the webhook).
     */
    public function store(Request $request, Creator $creator, Plan $plan): Response
    {
        abort_unless($plan->creator_id === $creator->id, 404);

        $plan->setRelation('creator', $creator);

        return Inertia::location($this->checkout->begin($request->user(), $plan));
    }

    public function update(Request $request, Support $support): RedirectResponse
    {
        $this->authorizeOwner($request, $support);

        $planId = $request->validate(['plan_id' => ['required', 'integer']])['plan_id'];
        $plan = Plan::query()->whereKey($planId)->first();
        abort_unless($plan !== null && $plan->creator_id === $support->creator_id, 404);

        return $this->manage(function () use ($support, $plan): string {
            $this->supports->changePlan($support, $plan);

            return 'プランを変更しました。';
        });
    }

    public function destroy(Request $request, Support $support): RedirectResponse
    {
        $this->authorizeOwner($request, $support);

        $billing = $support->isBilling();

        return $this->manage(function () use ($support, $billing): string {
            $periodEnd = $this->supports->cancel($support)->current_period_end;

            return match (true) {
                ! $billing => '支援を停止しました。',
                $periodEnd === null => '支援の停止を予約しました。',
                default => '支援の停止を予約しました。'.$periodEnd->format('Y年n月j日').'まで閲覧できます。',
            };
        });
    }

    public function resume(Request $request, Support $support): RedirectResponse
    {
        $this->authorizeOwner($request, $support);

        return $this->manage(function () use ($support): string {
            $this->supports->resume($support);

            return '支援を再開しました。';
        });
    }

    private function authorizeOwner(Request $request, Support $support): void
    {
        abort_unless($support->user_id === $request->user()->id, 404);
    }

    /**
     * Run the operation and flash the toast it returns; a failing payment service becomes an error toast instead of a 500.
     *
     * @param  callable(): string  $operation
     */
    private function manage(callable $operation): RedirectResponse
    {
        try {
            $message = $operation();
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
