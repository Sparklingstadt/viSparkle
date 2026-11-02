<?php

namespace App\Http\Controllers\Creator;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\StoreProductRequest;
use App\Http\Requests\Creator\UpdateProductRequest;
use App\Models\Creator;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    private const MAX_STOCK = 99_999;

    public function index(Request $request): Response
    {
        $creator = $request->user()->creator;

        $products = $creator->products()
            ->with('images')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'stock' => $product->stock,
                'is_published' => $product->is_published,
                'hidden' => $product->hidden_at !== null,
                'cover_url' => $product->coverUrl(),
            ]);

        return Inertia::render('creator/products/index', [
            'products' => $products,
            'can_publish' => $creator->canAcceptSupports(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('creator/products/edit', [
            'product' => null,
            'can_publish' => $request->user()->creator->canAcceptSupports(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $creator = $request->user()->creator;
        $published = $request->boolean('is_published');

        $this->ensureCanPublish($creator, $published);

        $product = $creator->products()->create([
            ...$request->safe()->only(['name', 'price', 'stock']),
            'description' => $request->validated('description') ?: null,
            'is_published' => $published,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => '商品を作成しました。画像を追加できます。']);

        return to_route('creator.products.edit', $product);
    }

    public function edit(Request $request, Product $product): Response
    {
        abort_unless($request->user()->can('update', $product), 403);

        return Inertia::render('creator/products/edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->price,
                'stock' => $product->stock,
                'reserved' => (int) OrderItem::query()
                    ->where('product_id', $product->id)
                    ->whereHas('order', fn (Builder $order) => $order->where('status', OrderStatus::Pending))
                    ->sum('quantity'),
                'is_published' => $product->is_published,
                'hidden' => $product->hidden_at !== null,
                'hidden_reason' => $product->hidden_reason,
                'images' => $product->images->map(fn (ProductImage $image) => [
                    'id' => $image->id,
                    'url' => $image->url(),
                ])->values(),
            ],
            'can_publish' => $product->creator->canAcceptSupports(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $published = $request->boolean('is_published');

        if ($published && ! $product->is_published) {
            $this->ensureCanPublish($product->creator, true);
        }

        // Apply the creator's change of stock to the current stock, under a lock, so a sale (or a pending checkout's
        // reservation) made while the form was open is not undone.
        DB::transaction(function () use ($request, $product, $published) {
            $current = Product::query()->lockForUpdate()->findOrFail($product->id);
            $change = $request->integer('stock') - $request->integer('stock_seen');

            $current->fill([
                ...$request->safe()->only(['name', 'price']),
                'stock' => max(0, min(self::MAX_STOCK, $current->stock + $change)),
                'description' => $request->validated('description') ?: null,
                'is_published' => $published,
            ])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => '商品を更新しました。']);

        return to_route('creator.products.edit', $product);
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->can('update', $product), 403);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => '商品を削除しました。']);

        return to_route('creator.products.index');
    }

    private function ensureCanPublish(Creator $creator, bool $publishing): void
    {
        if ($publishing && ! $creator->canAcceptSupports()) {
            throw ValidationException::withMessages([
                'is_published' => '口座の登録が済むと公開できます。',
            ]);
        }
    }
}
