<?php

namespace App\Http\Controllers\Admin;

use App\Billing\BillingException;
use App\Enums\OrderStatus;
use App\Enums\SupportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Models\AdminAction;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Billing\Refunds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $creator = $this->filter($request, 'creator');
        $user = $this->filter($request, 'user');

        $payments = Payment::query()
            ->with(['creator', 'user', 'support'])
            ->when($creator, fn ($query) => $query->whereHas('creator', fn ($q) => $q->where('slug', $creator)))
            ->when($user, fn ($query) => $query->whereHas('user', fn ($q) => $q->where('email', $user)))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $payments->through(fn (Payment $payment) => [
            'id' => $payment->id,
            'paid_at' => $payment->paid_at,
            'creator' => $payment->creator === null ? null : ['name' => $payment->creator->display_name, 'slug' => $payment->creator->slug],
            'supporter' => $payment->user === null ? null : ['name' => $payment->user->name, 'email' => $payment->user->email],
            'amount' => $payment->amount,
            'refunded_amount' => $payment->refunded_amount,
            'refunded' => $payment->refunded_amount >= $payment->amount,
            'disputed' => $payment->disputed_at !== null,
            'support_active' => $payment->support?->isBilling() === true && $payment->support->status === SupportStatus::Active,
        ]);

        $orders = Order::query()
            ->with(['creator', 'user', 'items'])
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Refunded])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(50, pageName: 'orders_page')
            ->withQueryString();

        $orders->through(fn (Order $order) => [
            'id' => $order->id,
            'paid_at' => $order->paid_at,
            'creator' => $order->creator === null ? null : ['name' => $order->creator->display_name, 'slug' => $order->creator->slug],
            'buyer' => $order->user === null ? null : ['name' => $order->user->name, 'email' => $order->user->email],
            'total' => $order->total,
            'has_digital' => $order->hasDigital(),
            'has_physical' => $order->hasPhysical(),
            'support_amount' => $order->support_amount,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'refundable' => in_array($order->status, [OrderStatus::Paid, OrderStatus::Shipped], true),
        ]);

        return Inertia::render('admin/payments', [
            'payments' => $payments,
            'orders' => $orders,
            'filters' => ['creator' => $creator, 'user' => $user],
        ]);
    }

    public function refund(RefundPaymentRequest $request, Payment $payment, Refunds $refunds): RedirectResponse
    {
        $cancelSupport = $request->boolean('cancel_support');

        try {
            $refunds->refund($payment, $request->user(), $cancelSupport);
        } catch (BillingException $e) {
            Log::error('billing.ops_failed', ['operation' => 'refund', 'payment_id' => $payment->id, 'message' => $e->getMessage()]);
            AdminAction::record($request->user(), 'payment.refund_failed', $payment, mb_substr($e->getMessage(), 0, 1000));
            Inertia::flash('toast', ['type' => 'error', 'message' => '返金できませんでした。時間をおいてお試しください。']);

            return back();
        }

        // Refunds logs a failed cancellation instead of throwing; the support is then still active.
        $support = $payment->support?->refresh();

        Inertia::flash('toast', $cancelSupport && $support?->isBilling() === true && $support->status === SupportStatus::Active
            ? ['type' => 'error', 'message' => '返金しましたが、支援を解約できませんでした。']
            : ['type' => 'success', 'message' => '返金しました。']);

        return back();
    }

    private function filter(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : $value;
    }
}
