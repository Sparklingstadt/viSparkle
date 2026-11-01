<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Post;
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
        abort_unless($post->isPublished() || $entitlement->isAuthorOf($post), 404);

        $post->setRelation('creator', $creator)->load('cover');
        $isOwner = $entitlement->isAuthorOf($post);
        $support = CreatorPageController::activeSupport($viewer, $creator);

        return Inertia::render('public/post', [
            'post' => $presenter->detail($post, $entitlement),
            'creator' => [
                ...$creator->toSummary(),
                'bio' => $creator->bio,
                'header_url' => $creator->headerUrl(),
            ],
            'plans' => CreatorPageController::visiblePlans($creator, $isOwner, $support),
            'isOwner' => $isOwner,
            'viewerSupportPrice' => $entitlement->supportPriceFor($creator->id),
            'viewerPlanId' => $support?->plan_id,
            'viewerSupportId' => $support?->id,
            'viewerSupportCancelling' => $support->cancel_at_period_end ?? false,
            'acceptsSupports' => $creator->canAcceptSupports(),
        ]);
    }
}
