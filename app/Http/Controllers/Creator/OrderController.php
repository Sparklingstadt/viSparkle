<?php

namespace App\Http\Controllers\Creator;

use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Presenters\OrderPresenter;
use App\Services\Shop\Orders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly Orders $orders,
        private readonly OrderPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        // The list opens on the orders waiting to be shipped; "all" shows every order that was paid.
        $all = $request->query('status') === 'all';
        $status = $all ? null : (OrderStatus::tryFrom((string) $request->query('status')) ?? OrderStatus::Paid);

        $orders = $request->user()->creator->orders()
            ->when(
                $status,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', OrderStatus::everPaid()),
            )
            ->with(['items', 'user'])
            // Orders to ship are worked through oldest first; the other lists show the newest first.
            ->when(
                $status === OrderStatus::Paid,
                fn ($query) => $query->oldest()->oldest('id'),
                fn ($query) => $query->latest()->latest('id'),
            )
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Order $order) => [
                ...$this->presenter->summary($order),
                'support_amount' => $order->support_amount,
                'buyer_name' => $order->user?->name,
                'shipping_name' => $order->shipping_name,
                'can_ship' => $order->isShippable(),
            ]);

        $counts = $request->user()->creator->orders()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('creator/orders/index', [
            'orders' => $orders,
            // "all" is what the list shows without a filter: the orders that were paid.
            'counts' => [
                'all' => (int) collect(OrderStatus::everPaid())->sum(fn (OrderStatus $case) => $counts[$case->value] ?? 0),
                ...collect(OrderStatus::cases())->mapWithKeys(fn (OrderStatus $case) => [$case->value => (int) ($counts[$case->value] ?? 0)])->all(),
            ],
            'status' => $status->value ?? 'all',
            'statuses' => collect(OrderStatus::cases())->map(fn (OrderStatus $case) => ['value' => $case->value, 'label' => $case->label()])->values(),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($request->user()->can('manage', $order), 404);

        $order->load(['items', 'user']);

        return Inertia::render('creator/orders/show', [
            'order' => [
                ...$this->presenter->detail($order),
                'buyer_name' => $order->user?->name,
                // Digital-only orders have nothing to ship; an order with a digital work is refunded by an admin only.
                'can_ship' => $order->isShippable(),
                'can_refund' => $order->isRefundableBy(OrderCancelReason::Creator),
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

        // Orders::refund() refuses a shipped order or one with a digital work with a validation error (key `order`).
        $this->orders->refund($order, OrderCancelReason::Creator);

        Inertia::flash('toast', ['type' => 'success', 'message' => '取り消して返金しました']);

        return back();
    }
}
