<?php

namespace App\Http\Controllers\Fan;

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

    public function destroy(Request $request, Support $support): RedirectResponse
    {
        abort_unless($support->user_id === $request->user()->id, 404);

        $this->supports->end($support);

        Inertia::flash('toast', ['type' => 'success', 'message' => '支援を停止しました。']);

        return back();
    }
}
