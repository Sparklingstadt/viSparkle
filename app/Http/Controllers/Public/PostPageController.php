<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
use App\Models\PostLike;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostPageController extends Controller
{
    public function __invoke(Request $request, Creator $creator, Post $post, PostPresenter $presenter): Response
    {
        $viewer = $request->user();
        $entitlement = Entitlement::for($viewer);

        abort_unless($post->creator_id === $creator->id, 404);
        abort_unless(($post->isPublished() && ! $post->isHidden()) || $entitlement->isAuthorOf($post), 404);

        $post->setRelation('creator', $creator)->load(['cover', 'category']);
        $isOwner = $entitlement->isAuthorOf($post);
        if ($post->isPublished()) {
            $post->recordView($viewer, $request->session());
        }
        $support = CreatorPageController::activeSupport($viewer, $creator);

        $morePosts = $creator->posts()
            ->published()
            ->visible()
            ->whereKeyNot($post->id)
            ->with('cover')
            ->latest('published_at')
            ->limit(4)
            ->get();
        $morePostsLiked = PostLike::idsLikedBy($viewer, $morePosts->modelKeys());
        $morePosts = $morePosts->map(fn (Post $other) => $presenter->summary($other->setRelation('creator', $creator), $entitlement, $morePostsLiked));

        return Inertia::render('public/post', [
            'post' => [
                ...$presenter->detail($post, $entitlement, PostLike::idsLikedBy($viewer, [$post->id])),
                'category' => $post->publicCategory()?->toSummary(),
            ],
            'creator' => [
                'id' => $creator->id,
                ...$creator->toSummary(),
                'bio' => $creator->bio,
                'header_url' => $creator->headerUrl(),
            ],
            'morePosts' => $morePosts,
            'plans' => CreatorPageController::visiblePlans($creator, $isOwner, $support),
            'isOwner' => $isOwner,
            'viewerSupportPrice' => $entitlement->supportPriceFor($creator->id),
            'viewerPlanId' => $support?->plan_id,
            'viewerSupportId' => $support?->id,
            'viewerSupportCancelling' => $support->cancel_at_period_end ?? false,
            'acceptsSupports' => $creator->canAcceptSupports(),
            'closed' => $creator->isClosed(),
            'reportReasons' => ReportReason::options(),
            // Only the author is told why the operators hid the post; everyone else gets a 404 above.
            'hiddenReason' => $isOwner && $post->isHidden() ? (string) $post->hidden_reason : null,
        ]);
    }
}
