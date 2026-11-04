<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLike;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryPageController extends Controller
{
    public function __invoke(Request $request, ProductCategory $category): Response
    {
        abort_if($category->isArchived(), 404);

        $products = Product::listed()
            ->inCategory($category)
            ->whereHas('creator', fn ($query) => $query->open())
            ->with(['creator', 'images', 'variants'])
            ->latest()
            ->latest('id')
            ->paginate(24)
            ->withQueryString();
        $liked = ProductLike::idsLikedBy($request->user(), $products->getCollection()->modelKeys());

        return Inertia::render('public/category', [
            'category' => $category->toSummary(),
            'categories' => ProductCategory::activeSummaries(),
            'products' => $products->through(fn (Product $product) => [
                ...$product->toSummary(),
                'creator' => $product->creator->toSummary(),
                'liked' => isset($liked[$product->id]),
                'can_like' => $product->creator->user_id !== $request->user()?->id,
            ]),
        ]);
    }
}
