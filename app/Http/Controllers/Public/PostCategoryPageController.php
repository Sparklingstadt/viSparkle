<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostLike;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostCategoryPageController extends Controller
{
    public function __invoke(Request $request, PostCategory $category, PostPresenter $presenter): Response
    {
        abort_if($category->isArchived(), 404);

        $entitlement = Entitlement::for($request->user());

        // Same audience as the home page: free, published, not hidden, author not suspended.
        $posts = Post::published()
            ->visible()
            ->inCategory($category)
            ->where('required_price', 0)
            ->whereHas('creator.user', fn ($query) => $query->whereNull('suspended_at'))
            ->with(['creator', 'cover'])
            ->paginate(20)
            ->withQueryString();
        $liked = PostLike::idsLikedBy($request->user(), $posts->getCollection()->modelKeys());

        return Inertia::render('public/post-category', [
            'category' => $category->toSummary(),
            'categories' => PostCategory::activeSummaries(),
            'posts' => $posts->through(fn (Post $post) => $presenter->summary($post, $entitlement, $liked)),
        ]);
    }
}
