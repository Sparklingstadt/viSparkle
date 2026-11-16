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

class PostPageController extends Controller
{
    public function __invoke(Request $request, Post $post, CreatorPresenter $creator, PostPresenter $presenter): Response
    {
        $viewer = $request->user();
        $entitlement = Entitlement::for($viewer);

        // A draft does not exist for anyone but the creator.
        abort_unless($post->isPublished() || $entitlement->isCreator(), 404);

        $post->load('cover');

        $morePosts = Post::published()
            ->whereKeyNot($post->id)
            ->with('cover')
            ->limit(4)
            ->get()
            ->map(fn (Post $other) => $presenter->summary($other, $entitlement));

        return Inertia::render('public/post', [
            'post' => $presenter->detail($post, $entitlement),
            'creator' => $creator->profile(),
            'morePosts' => $morePosts,
            ...$creator->supportPanel($viewer, $entitlement),
        ]);
    }
}
