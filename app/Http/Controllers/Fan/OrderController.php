<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
use App\Models\User;
use App\Services\Shop\DigitalAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(private readonly DigitalAccess $access) {}

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
                'status_label' => $order->statusLabel(),
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

        $order->load(['items.product.files', 'items.product.creator.user', 'creator']);

        return Inertia::render('fan/orders/show', [
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
                    'downloads' => $item->isDigital() ? $this->downloads($request->user(), $order, $item) : null,
                ])->values(),
                'has_physical' => $order->hasPhysical(),
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

    /**
     * The files of a purchased work, or why they cannot be downloaded now (the same check as the download itself).
     *
     * @return array{refusal: string|null, files: list<array{id: int, original_name: string, size: int, url: string}>}
     */
    private function downloads(User $buyer, Order $order, OrderItem $item): array
    {
        $work = $item->product;
        $refusal = $work === null
            ? 'この作品は現在公開されていないため、ダウンロードできません'
            : $this->access->refusal($buyer, $order, $work);

        if ($refusal !== null || $work === null) {
            return ['refusal' => $refusal, 'files' => []];
        }

        return [
            'refusal' => null,
            'files' => array_values($work->files->map(fn (ProductFile $file) => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'size' => $file->size,
                'url' => route('orders.files.download', [$order, $file]),
            ])->all()),
        ];
    }
}
