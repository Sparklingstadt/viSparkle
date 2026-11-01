<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
use App\Services\Entitlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Both actions are idempotent, so a double click or a resend never flips the state back.
 */
class PostLikeController extends Controller
{
    public function store(Request $request, Creator $creator, Post $post): RedirectResponse
    {
        $this->ensureLikeable($creator, $post);
        abort_unless(Entitlement::for($request->user())->canLike($post), 403);

        $post->like($request->user());

        return back();
    }

    public function destroy(Request $request, Creator $creator, Post $post): RedirectResponse
    {
        // Unliking needs no access: a fan whose support ended can still take back an old like.
        $this->ensureLikeable($creator, $post);

        $post->unlike($request->user());

        return back();
    }

    /**
     * Hidden posts and drafts don't exist for anyone but the author, who can't like their own posts anyway.
     */
    private function ensureLikeable(Creator $creator, Post $post): void
    {
        abort_unless($post->creator_id === $creator->id, 404);
        abort_unless($post->isPublished() && ! $post->isHidden(), 404);
    }
}
