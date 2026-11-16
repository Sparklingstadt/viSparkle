<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Post;
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

        $posts = Post::published()
            ->free()
            ->with(['creator', 'cover'])
            ->limit(8)
            ->get()
            ->map(fn (Post $post) => $presenter->summary($post, $entitlement));

        return Inertia::render('public/home', [
            'posts' => $posts,
        ]);
    }
}
