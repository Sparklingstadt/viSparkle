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
use App\Models\ProductVariant;
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
            ->with(['images', 'variants'])
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

        $product = DB::transaction(function () use ($request, $creator, $published) {
            $product = $creator->products()->create([
                ...$request->safe()->only(['name', 'price']),
                'description' => $request->validated('description') ?: null,
                'is_published' => $published,
            ]);

            foreach ($request->validated('variants') as $sort => $row) {
                $product->variants()->create(['name' => self::variantName($row), 'stock' => (int) $row['stock'], 'sort_order' => $sort]);
            }

            return $product;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => '商品を作成しました。画像を追加できます。']);

        return to_route('creator.products.edit', $product);
    }

    public function edit(Request $request, Product $product): Response
    {
        abort_unless($request->user()->can('update', $product), 403);

        $reserved = OrderItem::query()
            ->whereIn('product_variant_id', $product->variants->modelKeys())
            ->whereHas('order', fn (Builder $order) => $order->where('status', OrderStatus::Pending))
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(quantity) AS quantity')
            ->pluck('quantity', 'product_variant_id');

        return Inertia::render('creator/products/edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->price,
                'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'stock' => $variant->stock,
                    'reserved' => (int) ($reserved[$variant->id] ?? 0),
                ])->values(),
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

        // Apply the creator's change of each kind's stock to its current stock, under a lock, so a sale (or a pending
        // checkout's reservation) made while the form was open is not undone. Kinds left out of the form are removed.
        DB::transaction(function () use ($request, $product, $published) {
            $current = $product->variants()->lockForUpdate()->get()->keyBy('id');
            $rows = $request->validated('variants');
            $kept = array_filter(array_map(fn (array $row) => isset($row['id']) ? (int) $row['id'] : null, $rows));

            if (array_diff($kept, $current->keys()->all()) !== []) {
                throw ValidationException::withMessages(['variants' => '種類が正しくありません。画面を開き直してください。']);
            }

            $product->fill([
                ...$request->safe()->only(['name', 'price']),
                'description' => $request->validated('description') ?: null,
                'is_published' => $published,
            ])->save();

            $current->except($kept)->each(fn (ProductVariant $variant) => $variant->delete());

            foreach ($rows as $sort => $row) {
                $attributes = ['name' => self::variantName($row), 'sort_order' => $sort];

                if (! isset($row['id'])) {
                    $product->variants()->create([...$attributes, 'stock' => (int) $row['stock']]);

                    continue;
                }

                $variant = $current[(int) $row['id']];
                $change = (int) $row['stock'] - (int) $row['stock_seen'];
                $variant->update([...$attributes, 'stock' => max(0, min(self::MAX_STOCK, $variant->stock + $change))]);
            }
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

    /**
     * @param  array<string, mixed>  $row
     */
    private static function variantName(array $row): ?string
    {
        $name = trim((string) ($row['name'] ?? ''));

        return $name === '' ? null : $name;
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
