<?php

namespace App\Services;

use App\Enums\SupportStatus;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts and ends supports. In sub-project 1 this is the whole lifecycle;
 * sub-project 2 drives it from Stripe subscription events instead.
 */
class SupportService
{
    /**
     * Start supporting the plan's creator; switching plans ends the current support.
     *
     * @throws ValidationException
     */
    public function start(User $user, Plan $plan): Support
    {
        return DB::transaction(function () use ($user, $plan) {
            // Serialize concurrent requests by the same user so only one support can be active per creator.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $plan = Plan::query()->with('creator')->lockForUpdate()->findOrFail($plan->id);

            if ($plan->creator->user_id === $user->id) {
                throw ValidationException::withMessages(['plan' => '自分自身を支援することはできません。']);
            }

            $current = Support::active()
                ->where('user_id', $user->id)
                ->where('creator_id', $plan->creator_id)
                ->first();

            if ($current?->plan_id === $plan->id) {
                return $current;
            }

            if (! $plan->is_accepting) {
                throw ValidationException::withMessages(['plan' => 'このプランは現在受付を停止しています。']);
            }

            if ($current !== null) {
                $this->end($current);
            }

            return Support::create([
                'user_id' => $user->id,
                'creator_id' => $plan->creator_id,
                'plan_id' => $plan->id,
                'price' => $plan->price,
                'status' => SupportStatus::Active,
                'started_at' => now(),
            ]);
        });
    }

    public function end(Support $support): void
    {
        if ($support->status === SupportStatus::Ended) {
            return;
        }

        $support->update(['status' => SupportStatus::Ended, 'ended_at' => now()]);
    }
}
