<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Billing\Status\SubscriptionStatus;

/**
 * Cancels a subscription at once and syncs its support, for the flows that must stop the charges right away
 * (account deletion, suspension).
 */
class SubscriptionCanceller
{
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
            if (SubscriptionStatus::tryFrom($this->gateway->retrieveSubscription($subscriptionId)->status)?->isEnded() !== true) {
                throw $e;
            }
        }

        $this->sync->sync($subscriptionId);
    }
}
