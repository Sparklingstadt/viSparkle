<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Models\Support;
use App\Models\User;

/**
 * Deletes an account after cancelling its billing supports at once, so no subscription keeps charging a deleted user.
 */
class AccountDeletion
{
    public function __construct(private readonly SubscriptionCanceller $canceller) {}

    /**
     * Any cancellation that fails aborts before the user is deleted (supports cancelled so far stay cancelled).
     *
     * @throws BillingException
     */
    public function delete(User $user): void
    {
        $supports = Support::active()->billing()->where('user_id', $user->id)->orderBy('id')->get();

        foreach ($supports as $support) {
            $this->canceller->cancelNow((string) $support->stripe_subscription_id);
        }

        $user->delete();
    }
}
