<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Presenters\CreatorPresenter;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The creator's page: who they are, the plans to support, and the posts.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, CreatorPresenter $creator, PostPresenter $presenter): Response
    {
        $viewer = $request->user();
        $entitlement = Entitlement::for($viewer);

        $posts = Post::published()
            ->with('cover')
            ->paginate(20)
            ->through(fn (Post $post) => $presenter->summary($post, $entitlement));

        return Inertia::render('public/home', [
            'creator' => $creator->profile(),
            'posts' => $posts,
            ...$creator->supportPanel($viewer, $entitlement),
        ]);
    }
}
