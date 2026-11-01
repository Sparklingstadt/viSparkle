<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Billing side of suspending a user: a suspended creator's supporters are cancelled at once and refunded their latest
 * payment; a suspended fan's own supports are cancelled at once without a refund. Unsuspending undoes neither.
 */
class CreatorSuspension
{
    /** Stripe statuses of a subscription that can no longer charge. */
    private const ENDED = ['canceled', 'incomplete_expired'];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
        private readonly Refunds $refunds,
    ) {}

    /**
     * Safe to run again: only supports still active are touched. A support with no recorded payment is cancelled only.
     *
     * @return array{cancelled: int, refunded: int, failed: int}
     */
    public function suspend(Creator $creator, User $admin): array
    {
        DB::transaction(function () use ($creator): void {
            $creator->plans()->update(['is_accepting' => false]);

            Support::active()->where('creator_id', $creator->id)->whereNull('stripe_subscription_id')
                ->update(['status' => SupportStatus::Ended, 'ended_at' => now()]);
        });

        $counts = ['cancelled' => 0, 'refunded' => 0, 'failed' => 0];

        $supports = Support::active()->where('creator_id', $creator->id)->whereNotNull('stripe_subscription_id')->orderBy('id')->get();

        foreach ($supports as $support) {
            try {
                $this->cancelNow((string) $support->stripe_subscription_id);
                $counts['cancelled']++;
            } catch (BillingException $e) {
                $counts['failed']++;
                $this->logFailure('creator_suspension_cancel', $support, $e);

                continue;
            }

            $payment = Payment::query()
                ->where('support_id', $support->id)
                ->whereColumn('refunded_amount', '<', 'amount')
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->first();

            if ($payment === null) {
                continue;
            }

            try {
                $this->refunds->refund($payment, $admin, false);
                $counts['refunded']++;
            } catch (BillingException $e) {
                $counts['failed']++;
                $this->logFailure('creator_suspension_refund', $support, $e, $payment);
            }
        }

        return $counts;
    }

    /**
     * Cancel the user's own billing supports at once, without refunds.
     *
     * @return array{cancelled: int, failed: int}
     */
    public function cancelFanSupports(User $user): array
    {
        $counts = ['cancelled' => 0, 'failed' => 0];

        $supports = Support::active()->where('user_id', $user->id)->whereNotNull('stripe_subscription_id')->orderBy('id')->get();

        foreach ($supports as $support) {
            try {
                $this->cancelNow((string) $support->stripe_subscription_id);
                $counts['cancelled']++;
            } catch (BillingException $e) {
                $counts['failed']++;
                $this->logFailure('fan_suspension_cancel', $support, $e);
            }
        }

        return $counts;
    }

    /**
     * @throws BillingException
     */
    private function cancelNow(string $subscriptionId): void
    {
        try {
            $this->gateway->cancelSubscriptionNow($subscriptionId);
        } catch (BillingException $e) {
            // Stripe refuses to cancel a subscription that already ended (e.g. a rerun after a failed sync): carry on if so.
            if (! in_array($this->gateway->retrieveSubscription($subscriptionId)->status, self::ENDED, true)) {
                throw $e;
            }
        }

        $this->sync->sync($subscriptionId);
    }

    private function logFailure(string $operation, Support $support, BillingException $e, ?Payment $payment = null): void
    {
        Log::error('billing.ops_failed', array_filter([
            'operation' => $operation,
            'support_id' => $support->id,
            'payment_id' => $payment?->id,
            'message' => $e->getMessage(),
        ], fn ($value) => $value !== null));
    }
}
