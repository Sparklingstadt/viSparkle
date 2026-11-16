<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;

/**
 * Cancels a subscription at once and syncs its support, for the flows that must stop the charges right away
 * (account deletion, suspension).
 */
class SubscriptionCanceller
{
    /** Stripe statuses of a subscription that can no longer charge. */
    private const ENDED = ['canceled', 'incomplete_expired'];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
    ) {}

    /**
     * Safe to run again: a subscription that already ended is only synced.
     *
     * @throws BillingException
     */
    public function cancelNow(string $subscriptionId): void
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
}
