<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReportReason;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class CreatorPageController extends Controller
{
    public function __invoke(Request $request, Creator $creator, PostPresenter $presenter): Response
    {
        $viewer = $request->user();
        $entitlement = Entitlement::for($viewer);
        $isOwner = $viewer !== null && $creator->user_id === $viewer->id;
        $support = self::activeSupport($viewer, $creator);

        $posts = $creator->posts()
            ->published()
            ->with('cover')
            ->paginate(20)
            ->through(function (Post $post) use ($creator, $presenter, $entitlement) {
                $post->setRelation('creator', $creator);

                return $presenter->summary($post, $entitlement);
            });

        return Inertia::render('public/creator', [
            'creator' => [
                'id' => $creator->id,
                ...$creator->toSummary(),
                'bio' => $creator->bio,
                'header_url' => $creator->headerUrl(),
            ],
            'plans' => self::visiblePlans($creator, $isOwner, $support),
            'posts' => $posts,
            'isOwner' => $isOwner,
            'viewerSupportPrice' => $entitlement->supportPriceFor($creator->id),
            'viewerPlanId' => $support?->plan_id,
            'viewerSupportId' => $support?->id,
            'viewerSupportCancelling' => $support->cancel_at_period_end ?? false,
            'acceptsSupports' => $creator->canAcceptSupports(),
            'closed' => $creator->isClosed(),
            'reportReasons' => ReportReason::options(),
        ]);
    }

    public static function activeSupport(?User $viewer, Creator $creator): ?Support
    {
        return $viewer === null
            ? null
            : Support::active()->where('user_id', $viewer->id)->where('creator_id', $creator->id)->first();
    }

    /**
     * Accepting plans, plus the viewer's own plan even if it stopped accepting; the owner sees all.
     *
     * @return Collection<int, array{id: int, name: string, description: string|null, price: int, cover_url: string|null, is_accepting: bool, active_supporters_count: int}>
     */
    public static function visiblePlans(Creator $creator, bool $isOwner, ?Support $support): Collection
    {
        return $creator->plans()
            ->unless($isOwner, fn ($query) => $query->where(fn ($q) => $q
                ->where('is_accepting', true)
                ->orWhere('id', $support?->plan_id)))
            ->get()
            ->map(fn (Plan $plan) => $plan->toData());
    }
}
