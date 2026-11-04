<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\Product;
use App\Models\ProductLike;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, PostPresenter $presenter): Response
    {
        $entitlement = Entitlement::for($request->user());
        $sort = $request->query('sort') === 'new' ? 'new' : 'popular';

        $posts = ($sort === 'popular' ? Post::popular() : Post::published())
            ->visible()
            ->where('required_price', 0)
            ->whereHas('creator.user', fn ($query) => $query->whereNull('suspended_at'))
            ->with(['creator', 'cover'])
            ->limit(8)
            ->get();
        $liked = PostLike::idsLikedBy($request->user(), $posts->modelKeys());
        $posts = $posts->map(fn (Post $post) => $presenter->summary($post, $entitlement, $liked));

        $products = Product::listed()
            ->whereHas('creator', fn ($query) => $query->open())
            ->with(['creator', 'images', 'variants'])
            ->latest()
            ->latest('id')
            ->limit(12)
            ->get();
        // Best sellers that aren't already among the new arrivals.
        $recommendedProducts = Product::listed()
            ->popular()
            ->whereHas('creator', fn ($query) => $query->open())
            ->whereNotIn('id', $products->modelKeys())
            ->with(['creator', 'images', 'variants'])
            ->limit(12)
            ->get();
        $likedProducts = ProductLike::idsLikedBy($request->user(), [...$products->modelKeys(), ...$recommendedProducts->modelKeys()]);
        $carouselItem = fn (Product $product) => [
            ...$product->toSummary(),
            'creator' => $product->creator->toSummary(),
            'liked' => isset($likedProducts[$product->id]),
            'can_like' => $product->creator->user_id !== $request->user()?->id,
        ];
        $popularCreators = Creator::weeklyPopular()->limit(25)->get()->map->toSummary();

        return Inertia::render('public/home', [
            'posts' => $posts,
            'products' => $products->map($carouselItem),
            'recommendedProducts' => $recommendedProducts->map($carouselItem),
            'popularCreators' => $popularCreators,
            'sort' => $sort,
        ]);
    }
}
