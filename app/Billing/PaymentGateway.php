<?php

namespace App\Billing;

use App\Billing\Data\AccountData;
use App\Billing\Data\ChargeData;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\InvoiceData;
use App\Billing\Data\PriceRef;
use App\Billing\Data\RefundData;
use App\Billing\Data\SubscriptionData;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;

/**
 * Every call to the payment provider goes through here; failures throw BillingException.
 */
interface PaymentGateway
{
    /** Create a customer for the user and return its id (the caller stores it). */
    public function createCustomer(User $user): string;

    /** Return the product/price for the plan, creating and saving them on the plan when missing. */
    public function ensurePrice(Plan $plan): PriceRef;

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSessionData;

    public function retrieveCheckoutSession(string $id): CheckoutSessionData;

    public function expireCheckoutSession(string $id): void;

    public function retrieveSubscription(string $id): SubscriptionData;

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): SubscriptionData;

    /**
     * Upgrade: charge proration now and apply only if paid (throws PaymentFailedException otherwise).
     * Downgrade: no proration.
     */
    public function changeSubscriptionPrice(string $subscriptionId, string $itemId, string $priceId, bool $chargeProrationNow): SubscriptionData;

    public function cancelSubscriptionNow(string $subscriptionId): void;

    /** Return the URL of the customer portal; the user comes back to $returnUrl. */
    public function createPortalSession(string $customerId, string $returnUrl): string;

    /** Create a connected account for the creator and return its id (the caller stores it). */
    public function createConnectAccount(Creator $creator): string;

    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string;

    public function createDashboardLoginLink(string $accountId): string;

    public function retrieveAccount(string $accountId): AccountData;

    public function retrieveInvoice(string $id): InvoiceData;

    /**
     * Refund the invoice's payment in full, taking back the creator's transfer and the platform fee.
     * Retrying the same invoice is idempotent at the provider for a while; an already refunded payment throws.
     */
    public function refundInvoice(string $invoiceId): RefundData;

    public function retrieveCharge(string $chargeId): ChargeData;
}
