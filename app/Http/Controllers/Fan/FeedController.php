<?php

namespace App\Http\Controllers\Fan;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Creator;
use App\Models\Follow;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\Support;
use App\Presenters\PostPresenter;
use App\Services\Entitlement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    /** How recent a post must be for its creator to get the "new" mark above the feed. */
    private const NEW_POST_DAYS = 3;

    public function __invoke(Request $request, PostPresenter $presenter): Response
    {
        $user = $request->user();
        $entitlement = Entitlement::for($user);
        $tab = $request->query('tab') === 'supporting' ? 'supporting' : 'all';
        $supported = Support::active()->where('user_id', $user->id)->select('creator_id');

        // "all" adds the free posts of followed creators; a supported creator shows every post, followed or not.
        $posts = Post::published()
            ->visible()
            ->whereHas('creator.user', fn ($query) => $query->whereNull('suspended_at'))
            ->when(
                $tab === 'all',
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereIn('creator_id', $supported)
                    ->orWhere(fn ($query) => $query
                        ->whereIn('creator_id', Follow::query()->where('user_id', $user->id)->select('creator_id'))
                        ->where('required_price', 0))),
                fn ($query) => $query->whereIn('creator_id', $supported),
            )
            ->with(['creator', 'cover'])
            ->paginate(20)
            ->withQueryString();
        $liked = PostLike::idsLikedBy($user, $posts->pluck('id'));
        $posts->through(fn (Post $post) => $presenter->summary($post, $entitlement, $liked));

        // The row of avatars above the posts: supported creators first, then the ones only followed.
        // `has_new` marks the ones who published within the last NEW_POST_DAYS days.
        $supportedIds = (clone $supported)->pluck('creator_id')->all();
        $creators = Creator::open()
            ->where(fn ($query) => $query
                ->whereIn('id', $supportedIds)
                ->orWhereIn('id', Follow::query()->where('user_id', $user->id)->select('creator_id')))
            ->withExists(['posts as has_new' => fn ($query) => $query
                ->where('status', PostStatus::Published)
                ->whereNull('hidden_at')
                ->whereBetween('published_at', [now()->subDays(self::NEW_POST_DAYS), now()])])
            ->orderBy('display_name')
            ->get()
            ->map(fn (Creator $creator) => [
                ...$creator->toSummary(),
                'supporting' => in_array($creator->id, $supportedIds, true),
                'has_new' => (bool) $creator->getAttribute('has_new'),
            ])
            ->sortByDesc('supporting')
            ->values();

        return Inertia::render('fan/feed', ['posts' => $posts, 'tab' => $tab, 'creators' => $creators]);
    }
}
