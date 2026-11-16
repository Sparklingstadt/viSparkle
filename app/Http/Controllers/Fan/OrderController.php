<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductFile;
use App\Models\User;
use App\Presenters\OrderPresenter;
use App\Services\Shop\DigitalAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly DigitalAccess $access,
        private readonly OrderPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $orders = $request->user()->orders()
            ->with(['items', 'creator'])
            ->latest()
            ->latest('id')
            ->paginate(20)
            ->through(fn (Order $order) => [
                ...$this->presenter->summary($order),
                'creator' => $order->creator?->toSummary(),
            ]);

        return Inertia::render('fan/orders/index', ['orders' => $orders]);
    }

    public function show(Request $request, Order $order): Response
    {
        abort_unless($request->user()->can('view', $order), 404);

        $order->load(['items.product.files', 'items.product.creator.user', 'creator']);

        return Inertia::render('fan/orders/show', [
            'order' => [
                ...$this->presenter->detail($order, fn (OrderItem $item) => [
                    'downloads' => $item->isDigital() ? $this->downloads($request->user(), $order, $item) : null,
                ]),
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
