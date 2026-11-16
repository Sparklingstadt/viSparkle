<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The last screen before payment, where the terms required by the 特定商取引法 are shown.
 */
class CheckoutConfirmController extends Controller
{
    public function __invoke(Request $request, CheckoutService $checkout, Plan $plan): Response|RedirectResponse
    {
        try {
            $checkout->assertCanBegin($request->user(), $plan);
        } catch (ValidationException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) collect($e->errors())->flatten()->first()]);

            return to_route('home');
        }

        $day = now('Asia/Tokyo')->day;

        return Inertia::render('fan/checkout-confirm', [
            'creatorName' => config('visparkle.creator.name'),
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'description' => $plan->description,
            ],
            'renewalDay' => $day.'日'.($day >= 29 ? '（月末日がない月は月末）' : ''),
        ]);
    }
}
