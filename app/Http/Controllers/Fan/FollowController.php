<?php

namespace App\Http\Controllers\Fan;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Both actions are idempotent, so a double click or a resend never flips the state back.
 */
class FollowController extends Controller
{
    public function store(Request $request, Creator $creator): RedirectResponse
    {
        // A closed creator posts nothing new; the page offers no button, so this only stops direct requests.
        abort_if($creator->user_id === $request->user()->id || $creator->isClosed(), 403);

        $creator->follow($request->user());

        return back();
    }

    public function destroy(Request $request, Creator $creator): RedirectResponse
    {
        // Unfollowing is always allowed, also after the creator closed.
        $creator->unfollow($request->user());

        return back();
    }
}
