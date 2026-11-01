<?php

namespace App\Http\Controllers\Dev;

use App\Billing\FakeGateway;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The stand-in for Stripe's hosted Checkout page; routes exist only with the fake billing driver.
 */
class FakeCheckoutController extends Controller
{
    public function show(FakeGateway $gateway, string $session): Response
    {
        $data = $gateway->describeSession($session) ?? abort(404);

        return Inertia::render('dev/fake-checkout', [
            'session_id' => $session,
            'amount' => $data['amount'],
            'plan_name' => Plan::query()->whereKey($data['plan_id'])->value('name'),
            'status' => $data['status'],
            'cancel_url' => $data['cancel_url'],
        ]);
    }

    public function pay(FakeGateway $gateway, string $session): RedirectResponse
    {
        $data = $gateway->describeSession($session) ?? abort(404);

        $gateway->completeCheckout($session);

        return redirect()->away(str_replace('{CHECKOUT_SESSION_ID}', $session, $data['success_url']));
    }
}
