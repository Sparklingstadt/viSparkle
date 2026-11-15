<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\ProductLike;
use App\Presenters\ProductPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LikedProductController extends Controller
{
    public function __invoke(Request $request, ProductPresenter $presenter): Response
    {
        // A product taken off sale drops out but keeps its like, so it comes back if it's listed again.
        $products = $request->user()->productLikes()
            ->whereHas('product', fn ($query) => $query->listed()->whereHas('creator', fn ($creator) => $creator->open()))
            ->with(['product.creator', 'product.images', 'product.variants'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->through(fn (ProductLike $like) => $presenter->card($like->product, $request->user(), [$like->product_id => true]));

        return Inertia::render('fan/liked-products', ['products' => $products]);
    }
}
