<?php

namespace App\Http\Controllers\Dev;

use App\Billing\FakeGateway;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\PaymentRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The stand-in for Stripe's hosted Checkout page; routes exist only with the fake billing driver.
 */
class FakeCheckoutController extends Controller
{
    public function show(Request $request, FakeGateway $gateway, string $session): Response
    {
        $data = $this->ownSession($request, $gateway, $session);

        return Inertia::render('dev/fake-checkout', [
            'session_id' => $session,
            'amount' => $data['amount'],
            'plan_name' => Plan::query()->whereKey($data['plan_id'])->value('name'),
            'status' => $data['status'],
            'cancel_url' => $data['cancel_url'],
        ]);
    }

    public function pay(Request $request, FakeGateway $gateway, PaymentRecorder $payments, string $session): RedirectResponse
    {
        $data = $this->ownSession($request, $gateway, $session);

        $subscription = $gateway->completeCheckout($session);

        // Stand in for the invoice.paid webhook, so local earnings show the first payment.
        $invoiceId = $gateway->latestInvoiceId($subscription->id);

        if ($invoiceId !== null) {
            $payments->record($invoiceId);
        }

        return redirect()->away(str_replace('{CHECKOUT_SESSION_ID}', $session, $data['success_url']));
    }

    /**
     * The session as the dev page shows it; another user's session is as good as missing.
     *
     * @return array{amount: int, plan_id: int, user_id: string|null, status: string, success_url: string, cancel_url: string}
     */
    private function ownSession(Request $request, FakeGateway $gateway, string $session): array
    {
        $data = $gateway->describeSession($session);

        abort_if($data === null || $data['user_id'] !== (string) $request->user()?->id, 404);

        return $data;
    }
}
