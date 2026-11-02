<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductPageController extends Controller
{
    public function __invoke(Request $request, Creator $creator, int $product): Response
    {
        abort_unless($creator->isShopVisible(), 404);

        // Not route-bound: a soft-deleted product is as good as missing, and so is another creator's.
        $product = Product::query()->listed()->where('creator_id', $creator->id)->with('images')->findOrFail($product);
        $viewer = $request->user();

        return Inertia::render('public/product', [
            'creator' => $creator->toSummary(),
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->price,
                'stock' => $product->stock,
                'images' => $product->images->map(fn ($image) => $image->url())->values(),
            ],
            'shipping_fee' => $creator->shipping_fee,
            'shop_notice' => $creator->shop_notice,
            'can_buy' => $creator->canSell(),
            'is_owner' => $viewer !== null && $creator->user_id === $viewer->id,
        ]);
    }
}
