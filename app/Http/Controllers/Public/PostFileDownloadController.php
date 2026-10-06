<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostFile;
use App\Services\Entitlement;
use App\Services\PostFileUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stable link for a post file: re-checks access on every click and redirects to a
 * freshly signed URL, so links keep working after the 5-minute signature expires.
 * No bytes pass through the app.
 */
class PostFileDownloadController extends Controller
{
    public function __invoke(Request $request, Creator $creator, Post $post, PostFile $file, PostFileUrls $urls): RedirectResponse
    {
        abort_unless(
            $post->creator_id === $creator->id
                && $file->post_id === $post->id
                && Entitlement::for($request->user())->canView($post),
            404,
        );

        return redirect()->away($urls->url($file));
    }
}
