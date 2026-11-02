<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = $request->user()->orders()
            ->with(['items', 'creator'])
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->through(fn (Order $order) => [
                'id' => $order->id,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'total' => $order->total,
                'created_at' => $order->created_at,
                'creator' => $order->creator?->toSummary(),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])->values(),
            ]);

        return Inertia::render('fan/orders/index', ['orders' => $orders]);
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($request->user()->can('view', $order), 404);

        $order->load(['items', 'creator']);

        return Inertia::render('fan/orders/show', [
            'order' => [
                'id' => $order->id,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'unit_price' => $item->unit_price,
                    'quantity' => $item->quantity,
                ])->values(),
                'subtotal' => $order->subtotal,
                'shipping_fee' => $order->shipping_fee,
                'total' => $order->total,
                'shipping_name' => $order->shipping_name,
                'shipping_postal_code' => $order->shipping_postal_code,
                'shipping_address' => $order->shipping_address,
                'shipping_phone' => $order->shipping_phone,
                'tracking_number' => $order->tracking_number,
                'refunded_amount' => $order->refunded_amount,
                'created_at' => $order->created_at,
                'paid_at' => $order->paid_at,
                'shipped_at' => $order->shipped_at,
                'creator' => $order->creator?->toSummary(),
                'contact_email' => $order->creator?->shop_contact_email,
            ],
        ]);
    }
}
