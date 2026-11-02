<?php

namespace App\Http\Controllers\Dev;

use App\Billing\FakeGateway;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Services\Billing\PaymentRecorder;
use App\Services\Shop\OrderSync;
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
            'mode' => $data['mode'],
            'plan_name' => Plan::query()->whereKey($data['plan_id'])->value('name'),
            'items' => $data['order_id'] === null ? [] : OrderItem::query()
                ->where('order_id', $data['order_id'])
                ->orderBy('id')
                ->get(['product_name', 'unit_price', 'quantity'])
                ->map(fn (OrderItem $item) => ['name' => $item->product_name, 'unit_price' => $item->unit_price, 'quantity' => $item->quantity])
                ->all(),
            'status' => $data['status'],
            'cancel_url' => $data['cancel_url'],
        ]);
    }

    public function pay(Request $request, FakeGateway $gateway, PaymentRecorder $payments, OrderSync $orders, string $session): RedirectResponse
    {
        $data = $this->ownSession($request, $gateway, $session);

        if ($data['mode'] === 'payment') {
            return $this->payOrder($request, $gateway, $orders, $session, $data['success_url']);
        }

        $subscription = $gateway->completeCheckout($session);

        // Stand in for the invoice.paid webhook, so local earnings show the first payment.
        $invoiceId = $gateway->latestInvoiceId($subscription->id);

        if ($invoiceId !== null) {
            $payments->record($invoiceId);
        }

        return redirect()->away(str_replace('{CHECKOUT_SESSION_ID}', $session, $data['success_url']));
    }

    /**
     * A goods order's session: pay, pay later (a delayed method) or let it expire, as the buyer's choice on the page.
     */
    private function payOrder(Request $request, FakeGateway $gateway, OrderSync $orders, string $session, string $successUrl): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:pay,later,expire']])['action'];

        if ($action === 'expire') {
            $gateway->expireCheckoutSession($session);
            // Stand in for the checkout.session.expired webhook.
            $orders->syncSession($session);

            return to_route('cart.index');
        }

        $gateway->completePaymentCheckout($session, paid: $action === 'pay');

        return redirect()->away(str_replace('{CHECKOUT_SESSION_ID}', $session, $successUrl));
    }

    /**
     * The session as the dev page shows it; another user's session is as good as missing.
     *
     * @return array{amount: int, plan_id: int, user_id: string|null, status: string, success_url: string, cancel_url: string, mode: string, order_id: int|null}
     */
    private function ownSession(Request $request, FakeGateway $gateway, string $session): array
    {
        $data = $gateway->describeSession($session);

        abort_if($data === null || $data['user_id'] !== (string) $request->user()?->id, 404);

        return $data;
    }
}
