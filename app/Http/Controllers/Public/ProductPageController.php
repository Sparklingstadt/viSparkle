<?php

namespace App\Http\Controllers\Public;

use App\Enums\OrderStatus;
use App\Enums\ProductKind;
use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductPageController extends Controller
{
    public function __invoke(Request $request, Creator $creator, int $product): Response
    {
        abort_unless($creator->isShopVisible(), 404);

        // Not route-bound: a soft-deleted product is as good as missing, and so is another creator's.
        $product = Product::query()->listed()->where('creator_id', $creator->id)->with(['images', 'variants', 'files', 'category'])->findOrFail($product);
        $viewer = $request->user();
        $digital = $product->isDigital();

        return Inertia::render('public/product', [
            'creator' => $creator->toSummary(),
            'product' => [
                'id' => $product->id,
                'kind' => $product->kind->value,
                'name' => $product->name,
                'category' => $product->publicCategory()?->toSummary(),
                'description' => $product->description,
                'price' => $product->price,
                'stock' => $product->stock,
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'stock' => $variant->stock,
                ])->values(),
                'images' => $product->images->map(fn ($image) => $image->url())->values(),
                'purchasable' => $product->isPurchasable(),
                // What a buyer gets, without the names: the type and size of each file.
                'files' => $digital ? $product->files->map(fn (ProductFile $file) => [
                    'extension' => Str::lower(pathinfo($file->path, PATHINFO_EXTENSION)),
                    'size' => $file->size,
                ])->values() : [],
                'files_total_size' => $digital ? (int) $product->files->sum('size') : 0,
            ],
            'purchased_order_id' => $digital && $viewer !== null ? $this->purchasedOrderId($viewer, $product) : null,
            'shipping_fee' => $creator->shipping_fee,
            'shop_notice' => $creator->shop_notice,
            'can_buy' => $creator->canSell(),
            'reportReasons' => ReportReason::options(),
            'is_owner' => $viewer !== null && $creator->user_id === $viewer->id,
        ]);
    }

    /**
     * The viewer's latest paid or shipped order holding the work.
     */
    private function purchasedOrderId(User $viewer, Product $product): ?int
    {
        $id = Order::query()
            ->where('user_id', $viewer->id)
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::Shipped])
            ->whereHas('items', fn ($items) => $items->where('product_id', $product->id)->where('product_kind', ProductKind::Digital))
            ->latest('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
