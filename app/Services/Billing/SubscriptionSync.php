<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\Data\SubscriptionData;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single writer of a support's billing state: mirrors the latest Stripe subscription onto its support row.
 * Always re-reads the subscription, so duplicate and out-of-order events converge on the same result.
 */
class SubscriptionSync
{
    /** @var array<string, SupportStatus> Stripe statuses not listed here (`incomplete`) never create or change a support. */
    private const STATUSES = [
        'active' => SupportStatus::Active,
        'trialing' => SupportStatus::Active,
        'past_due' => SupportStatus::Active,
        'canceled' => SupportStatus::Ended,
        'unpaid' => SupportStatus::Ended,
        'incomplete_expired' => SupportStatus::Ended,
        'paused' => SupportStatus::Ended,
    ];

    /** What the last apply() did: 'synced', 'orphan' (cancelled and refunded), 'duplicate' (cancelled) or 'foreign' (ignored). */
    private ?string $lastOutcome = null;

    public function __construct(private readonly PaymentGateway $gateway) {}

    public function lastOutcome(): ?string
    {
        return $this->lastOutcome;
    }

    /**
     * @throws BillingException
     */
    public function sync(string $subscriptionId): ?Support
    {
        return $this->apply($this->gateway->retrieveSubscription($subscriptionId));
    }

    public function apply(SubscriptionData $data): ?Support
    {
        $this->lastOutcome = null;

        // Another environment's subscription (test-mode accounts are shared, and `stripe listen` forwards everything): not ours to touch.
        $app = $data->metadata['app'] ?? null;

        if ($app !== null && $app !== config('fanbox.billing.idempotency_prefix')) {
            Log::info('billing.foreign_subscription', ['subscription' => $data->id, 'app' => $app]);
            $this->lastOutcome = 'foreign';

            return null;
        }

        // Find the owner before the transaction, so the transaction's first read (which fixes its REPEATABLE READ snapshot) is the user lock.
        $supportUserId = Support::query()->where('stripe_subscription_id', $data->id)->value('user_id');
        $userId = is_numeric($supportUserId) ? (int) $supportUserId : $this->metadataIds($data)['user_id'] ?? null;

        try {
            // Locking a missing subscription id takes a gap lock, so two first syncs can deadlock; write() is idempotent, so retry.
            $result = DB::transaction(fn () => $this->write($data, $userId, $app !== null), 3);
        } catch (UniqueConstraintViolationException $e) {
            // Another active support for the same user and creator was committed by a writer that skipped our lock.
            $ids = $this->metadataIds($data);
            $other = $ids === null ? null : $this->activeSupport($ids['user_id'], $ids['creator_id'], $data->id);

            if ($other === null) {
                throw $e;
            }

            $result = ['duplicate' => $other];
        }

        if (isset($result['orphan'])) {
            $this->lastOutcome = 'orphan';
            $this->cancelOrphan($data, $result['orphan']);

            return null;
        }

        if (isset($result['duplicate'])) {
            $this->lastOutcome = 'duplicate';
            $this->cancelDuplicate($data, $result['duplicate']);

            return $result['duplicate'];
        }

        $this->lastOutcome = 'synced';

        return $result['support'] ?? null;
    }

    /**
     * @return array{support?: Support|null, duplicate?: Support, orphan?: string}
     */
    private function write(SubscriptionData $data, ?int $userId, bool $ours): array
    {
        $ids = $this->metadataIds($data);

        // Lock the user first (as SupportService does), then the support, so syncs for the same user run one at a time.
        $user = $userId === null ? null : User::query()->whereKey($userId)->lockForUpdate()->first();
        $support = Support::query()->where('stripe_subscription_id', $data->id)->lockForUpdate()->first();

        $status = self::STATUSES[$data->status] ?? null;

        // Orphans are only cancelled when stamped as ours; older subscriptions without the stamp keep the mismatch handling.
        $orphanable = $ours && $support === null && $ids !== null && $status === SupportStatus::Active;

        // A new, charging subscription for a deleted user (e.g. a Checkout finished after the account was deleted).
        if ($orphanable && $user === null) {
            return ['orphan' => 'user_missing'];
        }

        $owner = $support === null ? $ids['user_id'] ?? null : $support->user_id;

        if ($user === null || $user->id !== $owner || $user->stripe_customer_id !== $data->customerId) {
            return $this->mismatch($data, $support);
        }

        // A new, charging subscription for a creator who can no longer take supports.
        if ($orphanable) {
            $reason = $this->closedCreatorReason((int) $ids['creator_id']);

            if ($reason !== null) {
                return ['orphan' => $reason];
            }
        }

        if ($status === null) {
            return ['support' => $support];
        }

        if ($support === null) {
            if ($ids === null || ! Creator::query()->whereKey($ids['creator_id'])->exists()) {
                return $this->mismatch($data, null);
            }

            $planId = $this->planForPrice($data, $ids['creator_id']);

            if ($planId === null) {
                // A metadata plan of another creator is a mismatch; a plan deleted since checkout just leaves plan_id empty.
                $plan = Plan::query()->whereKey($ids['plan_id'])->first();

                if ($plan !== null && $plan->creator_id !== $ids['creator_id']) {
                    return $this->mismatch($data, null);
                }

                $planId = $plan?->id;
            }

            $support = (new Support)->forceFill([
                'user_id' => $user->id,
                'creator_id' => $ids['creator_id'],
                'plan_id' => $planId,
                'started_at' => now(),
            ]);
        }

        if ($status === SupportStatus::Active && $support->status !== SupportStatus::Active) {
            $other = $this->activeSupport($support->user_id, $support->creator_id, $data->id);

            if ($other !== null) {
                return ['duplicate' => $other];
            }
        }

        $support->forceFill([
            'stripe_subscription_id' => $data->id,
            'plan_id' => $this->planForPrice($data, $support->creator_id) ?? $support->plan_id,
            'price' => $data->unitAmount ?? ($support->exists ? $support->price : $support->plan?->price) ?? 0,
            'status' => $status,
            'stripe_status' => $data->status,
            'current_period_end' => $data->currentPeriodEnd,
            'cancel_at_period_end' => $data->cancelAtPeriodEnd,
            'ended_at' => match (true) {
                $status === SupportStatus::Active => null,
                $support->status === SupportStatus::Ended => $support->ended_at,
                default => now(),
            },
        ])->save();

        return ['support' => $support];
    }

    /**
     * The subscription's customer, metadata and support do not belong together: record it and leave the data alone.
     *
     * @return array{support: null}
     */
    private function mismatch(SubscriptionData $data, ?Support $support): array
    {
        Log::warning('billing.metadata_mismatch', [
            'subscription' => $data->id,
            'customer' => $data->customerId,
            'metadata' => $data->metadata,
            'support_id' => $support?->id,
        ]);

        return ['support' => null];
    }

    /**
     * @return array{user_id: int, creator_id: int, plan_id: int}|null
     */
    private function metadataIds(SubscriptionData $data): ?array
    {
        $ids = [];

        foreach (['user_id', 'creator_id', 'plan_id'] as $key) {
            $value = $data->metadata[$key] ?? null;

            if (! is_string($value) || ! ctype_digit($value)) {
                return null;
            }

            $ids[$key] = (int) $value;
        }

        return $ids;
    }

    /**
     * The creator's plan for the subscription's price, or null when the price is not one of theirs.
     */
    private function planForPrice(SubscriptionData $data, int $creatorId): ?int
    {
        if ($data->priceId === null) {
            return null;
        }

        $plan = Plan::query()->where('stripe_price_id', $data->priceId)->where('creator_id', $creatorId)->first();

        return $plan?->id;
    }

    /**
     * A locking read, so it sees active supports committed after the transaction's snapshot was taken.
     */
    private function activeSupport(int $userId, int $creatorId, string $exceptSubscriptionId): ?Support
    {
        return Support::active()
            ->where('user_id', $userId)
            ->where('creator_id', $creatorId)
            ->where(fn ($query) => $query->whereNull('stripe_subscription_id')->orWhere('stripe_subscription_id', '!=', $exceptSubscriptionId))
            ->lockForUpdate()
            ->first();
    }

    private function closedCreatorReason(int $creatorId): ?string
    {
        $creator = Creator::query()->with('user')->whereKey($creatorId)->first();

        return match (true) {
            $creator === null => 'creator_missing',
            $creator->isClosed() => 'creator_closed',
            $creator->user->suspended_at !== null => 'creator_suspended',
            default => null,
        };
    }

    /**
     * A subscription that must not become a support: cancel it now and refund its latest (first) invoice.
     * Failures are logged, not thrown. A failed cancellation leaves the subscription active, so a later sync tries both again;
     * a refund that fails after a successful cancellation is not retried and needs a manual refund from the logs.
     */
    private function cancelOrphan(SubscriptionData $data, string $reason): void
    {
        Log::error('billing.orphan_subscription', [
            'subscription' => $data->id,
            'customer' => $data->customerId,
            'metadata' => $data->metadata,
            'reason' => $reason,
        ]);

        try {
            $this->gateway->cancelSubscriptionNow($data->id);
        } catch (BillingException $e) {
            Log::error('billing.orphan_cancel_failed', ['subscription' => $data->id, 'message' => $e->getMessage()]);
        }

        if ($data->latestInvoiceId === null) {
            return;
        }

        try {
            $this->gateway->refundInvoice($data->latestInvoiceId);
        } catch (BillingException $e) {
            Log::error('billing.orphan_refund_failed', [
                'subscription' => $data->id,
                'invoice' => $data->latestInvoiceId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A second subscription for a creator the user already supports: cancel it now (refunds are manual).
     */
    private function cancelDuplicate(SubscriptionData $data, Support $existing): void
    {
        Log::error('billing.duplicate_subscription', [
            'subscription' => $data->id,
            'user_id' => $existing->user_id,
            'creator_id' => $existing->creator_id,
        ]);

        try {
            $this->gateway->cancelSubscriptionNow($data->id);
        } catch (BillingException $e) {
            Log::error('billing.duplicate_cancel_failed', ['subscription' => $data->id, 'message' => $e->getMessage()]);
        }
    }
}
