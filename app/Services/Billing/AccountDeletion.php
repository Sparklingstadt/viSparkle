<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Models\Support;
use App\Models\User;

/**
 * Deletes an account after cancelling its billing supports at once, so no subscription keeps charging a deleted user.
 */
class AccountDeletion
{
    /** Stripe statuses of a subscription that can no longer charge. */
    private const ENDED = ['canceled', 'incomplete_expired'];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
    ) {}

    /**
     * Any cancellation that fails aborts before the user is deleted (supports cancelled so far stay cancelled).
     *
     * @throws BillingException
     */
    public function delete(User $user): void
    {
        $supports = Support::active()->where('user_id', $user->id)->whereNotNull('stripe_subscription_id')->orderBy('id')->get();

        foreach ($supports as $support) {
            $this->cancel((string) $support->stripe_subscription_id);
        }

        $user->delete();
    }

    /**
     * @throws BillingException
     */
    private function cancel(string $subscriptionId): void
    {
        try {
            $this->gateway->cancelSubscriptionNow($subscriptionId);
        } catch (BillingException $e) {
            // Stripe refuses to cancel a subscription that already ended (e.g. a retry after a failed sync): carry on if so.
            if (! in_array($this->gateway->retrieveSubscription($subscriptionId)->status, self::ENDED, true)) {
                throw $e;
            }
        }

        $this->sync->sync($subscriptionId);
    }
}
