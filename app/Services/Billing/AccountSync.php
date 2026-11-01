<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Models\Creator;

/**
 * Mirrors a creator's Connect account state, which decides whether the creator can take new supports.
 */
class AccountSync
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @throws BillingException
     */
    public function sync(string $accountId): ?Creator
    {
        $creator = Creator::query()->where('stripe_account_id', $accountId)->first();

        if ($creator === null) {
            return null;
        }

        $account = $this->gateway->retrieveAccount($accountId);
        $creator->forceFill([
            'payouts_enabled' => $account->payoutsEnabled,
            'details_submitted' => $account->detailsSubmitted,
        ])->save();

        return $creator;
    }

    /**
     * The creator disconnected the platform from their account.
     */
    public function deauthorize(string $accountId): void
    {
        Creator::query()->where('stripe_account_id', $accountId)->update(['payouts_enabled' => false]);
    }
}
