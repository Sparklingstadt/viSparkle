<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SearchController extends Controller
{
    private const MAX_RESULTS = 8;

    private const MAX_KEYWORD_LENGTH = 100;

    private const TYPES = ['all', 'creators', 'products', 'posts'];

    public function __invoke(Request $request, PostPresenter $presenter): Response
    {
        $raw = $request->query('q');
        $q = is_string($raw) ? mb_substr(Str::trim($raw), 0, self::MAX_KEYWORD_LENGTH) : '';
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : 'all';
        $slug = $request->query('category');
        $category = $q !== '' && is_string($slug) ? ProductCategory::active()->where('slug', $slug)->first() : null;
        $wants = fn (string $kind): bool => $q !== '' && in_array($type, ['all', $kind], true);

        return Inertia::render('public/search', [
            'q' => $q,
            'type' => $type,
            'category' => $category?->slug,
            'categories' => $q !== '' ? ProductCategory::activeSummaries() : [],
            'creators' => $wants('creators') ? $this->creators($q) : [],
            'products' => $wants('products') ? $this->products($q, $category) : [],
            'posts' => $wants('posts') ? $this->posts($request, $presenter, $q) : [],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function creators(string $q): array
    {
        return $this->matching(Creator::open(), ['display_name', 'bio'], $q)
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map->toSummary()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products(string $q, ?ProductCategory $category): array
    {
        return $this->matching(Product::listed(), ['name', 'description'], $q)
            ->when($category, fn (Builder $query, ProductCategory $category) => $query->inCategory($category))
            ->whereHas('creator', fn ($query) => $query->open())
            ->with(['creator', 'images', 'variants'])
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (Product $product) => [...$product->toSummary(), 'creator' => $product->creator->toSummary()])
            ->all();
    }

    /**
     * Free posts only, as on the home page: a paid post's title stays out of a public listing here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function posts(Request $request, PostPresenter $presenter, string $q): array
    {
        $posts = $this->matching(Post::published()->visible(), ['title', 'excerpt'], $q)
            ->where('required_price', 0)
            ->whereHas('creator.user', fn ($query) => $query->whereNull('suspended_at'))
            ->with(['creator', 'cover'])
            ->limit(self::MAX_RESULTS)
            ->get();
        $entitlement = Entitlement::for($request->user());
        $liked = PostLike::idsLikedBy($request->user(), $posts->modelKeys());

        return $posts->map(fn (Post $post) => $presenter->summary($post, $entitlement, $liked))->all();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Builder<TModel>
     */
    private function matching(Builder $query, array $columns, string $q): Builder
    {
        $pattern = '%'.addcslashes($q, '\\%_').'%';

        return $query->where(function (Builder $query) use ($columns, $pattern) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', $pattern);
            }
        });
    }
}
