<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Closes a creator: no new supports, and every billing support ends with its paid period (supporters keep access until then).
 */
class CreatorClosure
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
    ) {}

    /**
     * Safe to run again: supports already scheduled to stop are skipped, so a rerun retries only the failed ones.
     *
     * @return array{cancelled: int, failed: int}
     */
    public function close(Creator $creator): array
    {
        DB::transaction(function () use ($creator): void {
            if (! $creator->isClosed()) {
                $creator->forceFill(['closed_at' => now()])->save();
            }

            $creator->plans()->update(['is_accepting' => false]);

            Support::active()->where('creator_id', $creator->id)->whereNull('stripe_subscription_id')
                ->update(['status' => SupportStatus::Ended, 'ended_at' => now()]);
        });

        $cancelled = 0;
        $failed = 0;

        $supports = Support::active()
            ->where('creator_id', $creator->id)
            ->whereNotNull('stripe_subscription_id')
            ->where('cancel_at_period_end', false)
            ->get();

        foreach ($supports as $support) {
            try {
                $this->sync->apply($this->gateway->setCancelAtPeriodEnd((string) $support->stripe_subscription_id, true));
                $cancelled++;
            } catch (BillingException $e) {
                $failed++;
                Log::error('billing.ops_failed', [
                    'operation' => 'creator_closure',
                    'support_id' => $support->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return ['cancelled' => $cancelled, 'failed' => $failed];
    }

    /**
     * Plans stay stopped; the creator reopens the ones they want.
     */
    public function reopen(Creator $creator): void
    {
        $creator->forceFill(['closed_at' => null])->save();
    }
}
