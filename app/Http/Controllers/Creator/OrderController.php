<?php

namespace App\Http\Controllers\Creator;

use App\Billing\BillingException;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Shop\Orders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(private readonly Orders $orders) {}

    public function index(Request $request): Response
    {
        $status = OrderStatus::tryFrom((string) $request->query('status'));

        $orders = $request->user()->creator->orders()
            ->when(
                $status,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Refunded]),
            )
            ->with(['items', 'user'])
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->status->value,
                'status_label' => $order->statusLabel(),
                'total' => $order->total,
                'created_at' => $order->created_at,
                'buyer_name' => $order->user?->name,
                'shipping_name' => $order->shipping_name,
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])->values(),
            ]);

        return Inertia::render('creator/orders/index', [
            'orders' => $orders,
            'status' => $status?->value,
            'statuses' => collect(OrderStatus::cases())->map(fn (OrderStatus $case) => ['value' => $case->value, 'label' => $case->label()])->values(),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($request->user()->can('manage', $order), 404);

        $order->load(['items', 'user']);

        return Inertia::render('creator/orders/show', [
            'order' => [
                'id' => $order->id,
                'status' => $order->status->value,
                'status_label' => $order->statusLabel(),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'product_kind' => $item->product_kind->value,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                ])->values(),
                'subtotal' => $order->subtotal,
                'shipping_fee' => $order->shipping_fee,
                'total' => $order->total,
                'buyer_name' => $order->user?->name,
                'shipping_name' => $order->shipping_name,
                'shipping_postal_code' => $order->shipping_postal_code,
                'shipping_address' => $order->shipping_address,
                'shipping_phone' => $order->shipping_phone,
                'tracking_number' => $order->tracking_number,
                'refunded_amount' => $order->refunded_amount,
                'created_at' => $order->created_at,
                'paid_at' => $order->paid_at,
                'shipped_at' => $order->shipped_at,
                // Digital-only orders have nothing to ship; an order with a digital work is refunded by an admin only.
                'can_ship' => $order->status === OrderStatus::Paid && ($order->hasPhysical() || ! $order->hasDigital()),
                'can_refund' => $order->status === OrderStatus::Paid && ! $order->hasDigital(),
            ],
        ]);
    }

    public function ship(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->can('manage', $order), 404);

        $data = $request->validate(['tracking_number' => ['nullable', 'string', 'max:100']]);

        $this->orders->ship($order, $data['tracking_number'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => '発送済みにしました']);

        return back();
    }

    public function refund(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->can('manage', $order), 404);

        try {
            // Only a paid order: once shipped the goods are out, so the refund is settled outside the app.
            if ($order->refresh()->status !== OrderStatus::Paid) {
                return back()->withErrors(['order' => 'この注文は返金できません。']);
            }

            $this->orders->refund($order, OrderCancelReason::Creator);
        } catch (BillingException $e) {
            report($e);
            Inertia::flash('toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => '取り消して返金しました']);

        return back();
    }
}
