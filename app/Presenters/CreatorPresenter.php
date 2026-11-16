<?php

namespace App\Presenters;

use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Entitlement;

/**
 * The props every page about the creator shares: who they are (from config/visparkle.php) and the plans a viewer can
 * support, with the viewer's own support.
 */
class CreatorPresenter
{
    /**
     * @return array{name: string, bio: string|null, avatar_url: string, header_url: string}
     */
    public function profile(): array
    {
        return [
            'name' => (string) config('visparkle.creator.name'),
            'bio' => config('visparkle.creator.bio'),
            'avatar_url' => asset((string) config('visparkle.creator.avatar')),
            'header_url' => asset((string) config('visparkle.creator.header')),
        ];
    }

    /**
     * Accepting plans, plus the viewer's own plan even if it stopped accepting; the creator sees all.
     *
     * @return array<string, mixed>
     */
    public function supportPanel(?User $viewer, Entitlement $entitlement): array
    {
        $support = $viewer === null ? null : Support::active()->where('user_id', $viewer->id)->first();

        $plans = Plan::query()
            ->unless($entitlement->isCreator(), fn ($query) => $query->where(fn ($q) => $q
                ->where('is_accepting', true)
                ->orWhere('id', $support?->plan_id)))
            ->orderBy('sort')
            ->orderBy('price')
            ->get()
            ->map(fn (Plan $plan) => $plan->toData());

        return [
            'plans' => $plans,
            'isCreator' => $entitlement->isCreator(),
            'viewerSupportPrice' => $entitlement->supportPrice(),
            'viewerPlanId' => $support?->plan_id,
            'viewerSupportId' => $support?->id,
            'viewerSupportCancelling' => $support->cancel_at_period_end ?? false,
        ];
    }
}
