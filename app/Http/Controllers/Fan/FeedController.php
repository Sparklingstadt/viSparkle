<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Support;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    public function __invoke(Request $request, PostPresenter $presenter): Response
    {
        $entitlement = Entitlement::for($request->user());

        $posts = Post::published()
            ->visible()
            ->whereHas('creator.user', fn ($query) => $query->whereNull('suspended_at'))
            ->whereIn('creator_id', Support::active()->where('user_id', $request->user()->id)->select('creator_id'))
            ->with(['creator', 'cover'])
            ->paginate(20)
            ->through(fn (Post $post) => $presenter->summary($post, $entitlement));

        return Inertia::render('fan/feed', ['posts' => $posts]);
    }
}
