<?php

namespace App\Http\Controllers\Fan;

use App\Billing\BillingException;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Services\Shop\AwaitingPaymentException;
use App\Services\Shop\OrderCheckout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class OrderCheckoutController extends Controller
{
    public function __construct(private readonly OrderCheckout $checkout) {}

    /**
     * Reserve the stock and send the buyer to Stripe Checkout; the order is paid when they come back (or by the webhook).
     */
    public function store(Request $request, Creator $creator): Response
    {
        try {
            return Inertia::location($this->checkout->begin($request->user(), $creator));
        } catch (AwaitingPaymentException $e) {
            // Lead the buyer to the order whose payment is still being settled.
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('orders.show', $e->order);
        }
    }

    /**
     * Where Stripe Checkout sends the buyer back to; the webhook may not have arrived yet, so sync here.
     * The buyer lands on the order's page, where its state shows (or the cart when Stripe could not be reached).
     */
    public function complete(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        abort_unless(is_string($sessionId) && $sessionId !== '', 404);

        try {
            $order = $this->checkout->complete($request->user(), $sessionId);
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return to_route('cart.index');
        }

        Inertia::flash('toast', match ($order->status) {
            OrderStatus::Paid, OrderStatus::Shipped => ['type' => 'success', 'message' => 'ご注文ありがとうございました'],
            OrderStatus::Pending => ['type' => 'success', 'message' => 'お支払いをお待ちしています'],
            default => ['type' => 'error', 'message' => 'お支払いが完了していません。'],
        });

        return to_route('orders.show', $order);
    }
}
