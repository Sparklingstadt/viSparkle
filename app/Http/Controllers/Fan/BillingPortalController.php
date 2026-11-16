<?php

namespace App\Http\Controllers\Fan;

use App\Billing\PaymentGateway;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the fan to Stripe's customer portal (payment methods, invoices); they return to the supporting page.
 */
class BillingPortalController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway): Response
    {
        $customerId = $request->user()->stripe_customer_id;

        if ($customerId === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'お支払い情報がまだありません。']);

            return back();
        }

        return Inertia::location($gateway->createPortalSession($customerId, route('supporting.index')));
    }
}
