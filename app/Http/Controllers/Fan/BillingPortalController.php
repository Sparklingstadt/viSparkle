<?php

namespace App\Http\Controllers\Fan;

use App\Billing\BillingException;
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

        try {
            return Inertia::location($gateway->createPortalSession($customerId, route('supporting.index')));
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        }
    }
}
