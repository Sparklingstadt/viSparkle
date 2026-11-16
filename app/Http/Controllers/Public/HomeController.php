<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostLike;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductLike;
use App\Presenters\PostPresenter;
use App\Presenters\ProductPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, PostPresenter $presenter, ProductPresenter $productPresenter): Response
    {
        $entitlement = Entitlement::for($request->user());
        $sort = $request->query('sort') === 'new' ? 'new' : 'popular';

        $posts = ($sort === 'popular' ? Post::popular() : Post::published())
            ->free()
            ->with(['creator', 'cover'])
            ->limit(8)
            ->get();
        $liked = PostLike::idsLikedBy($request->user(), $posts->modelKeys());
        $posts = $posts->map(fn (Post $post) => $presenter->summary($post, $entitlement, $liked));

        $products = Product::listed()
            ->whereHas('creator', fn ($query) => $query->open())
            ->forCard()
            ->latest()
            ->latest('id')
            ->limit(12)
            ->get();
        // Best sellers that aren't already among the new arrivals.
        $recommendedProducts = Product::listed()
            ->popular()
            ->whereHas('creator', fn ($query) => $query->open())
            ->whereNotIn('id', $products->modelKeys())
            ->forCard()
            ->limit(12)
            ->get();
        $likedProducts = ProductLike::idsLikedBy($request->user(), [...$products->modelKeys(), ...$recommendedProducts->modelKeys()]);
        $carouselItem = fn (Product $product) => $productPresenter->card($product, $request->user(), $likedProducts);
        $popularCreators = Creator::weeklyPopular()->withMinPlanPrice()->limit(25)->get()->map->toCard();

        return Inertia::render('public/home', [
            'posts' => $posts,
            'products' => $products->map($carouselItem),
            'recommendedProducts' => $recommendedProducts->map($carouselItem),
            'popularCreators' => $popularCreators,
            'categories' => ProductCategory::activeSummaries(),
            'postCategories' => PostCategory::activeSummaries(),
            'sort' => $sort,
        ]);
    }
}
