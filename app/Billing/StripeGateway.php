<?php

namespace App\Billing;

use App\Billing\Data\AccountData;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\InvoiceData;
use App\Billing\Data\PriceRef;
use App\Billing\Data\SubscriptionData;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Subscription;

/**
 * Production gateway backed by Stripe. Stripe objects never leave this class; API-version differences are absorbed here.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $client) {}

    public function createCustomer(User $user): string
    {
        $customer = $this->call(fn () => $this->client->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string) $user->id],
        ], ['idempotency_key' => "user-{$user->id}-customer"]));

        return $customer->id;
    }

    public function ensurePrice(Plan $plan): PriceRef
    {
        if ($plan->stripe_product_id !== null && $plan->stripe_price_id !== null) {
            return new PriceRef($plan->stripe_product_id, $plan->stripe_price_id);
        }

        $product = $this->call(fn () => $this->client->products->create([
            'name' => "{$plan->creator->display_name} {$plan->name}",
            'metadata' => ['plan_id' => (string) $plan->id],
        ], ['idempotency_key' => "plan-{$plan->id}-product"]));

        $price = $this->call(fn () => $this->client->prices->create([
            'product' => $product->id,
            'currency' => 'jpy',
            'unit_amount' => $plan->price,
            'recurring' => ['interval' => 'month'],
            'tax_behavior' => 'inclusive',
        ], ['idempotency_key' => "plan-{$plan->id}-price"]));

        $plan->forceFill(['stripe_product_id' => $product->id, 'stripe_price_id' => $price->id])->save();

        return new PriceRef($product->id, $price->id);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSessionData
    {
        $session = $this->call(fn () => $this->client->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $request->customerId,
            'line_items' => [['price' => $request->priceId, 'quantity' => 1]],
            'payment_method_types' => ['card'],
            'locale' => 'ja',
            'expires_at' => $request->expiresAt->getTimestamp(),
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => $request->metadata,
            'subscription_data' => [
                'application_fee_percent' => $request->feePercent,
                'transfer_data' => ['destination' => $request->destinationAccountId],
                'metadata' => $request->metadata,
            ],
        ]));

        return self::sessionFrom($session);
    }

    public function retrieveCheckoutSession(string $id): CheckoutSessionData
    {
        return self::sessionFrom($this->call(fn () => $this->client->checkout->sessions->retrieve($id)));
    }

    public function expireCheckoutSession(string $id): void
    {
        $this->call(fn () => $this->client->checkout->sessions->expire($id));
    }

    public function retrieveSubscription(string $id): SubscriptionData
    {
        return self::subscriptionFrom($this->call(fn () => $this->client->subscriptions->retrieve($id)));
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): SubscriptionData
    {
        return self::subscriptionFrom($this->call(
            fn () => $this->client->subscriptions->update($subscriptionId, ['cancel_at_period_end' => $cancel]),
        ));
    }

    public function changeSubscriptionPrice(string $subscriptionId, string $itemId, string $priceId, bool $chargeProrationNow): SubscriptionData
    {
        try {
            $subscription = $this->call(fn () => $this->client->subscriptions->update($subscriptionId, [
                'items' => [['id' => $itemId, 'price' => $priceId]],
                'proration_behavior' => $chargeProrationNow ? 'always_invoice' : 'none',
                'payment_behavior' => $chargeProrationNow ? 'pending_if_incomplete' : 'allow_incomplete',
            ]));
        } catch (BillingException $e) {
            // A declined card on the proration invoice is a failed upgrade, not a gateway outage.
            throw $e->getPrevious() instanceof CardException
                ? new PaymentFailedException($e->getMessage(), previous: $e->getPrevious())
                : $e;
        }

        // With pending_if_incomplete the change is held back until the proration invoice is paid.
        if (($subscription->toArray()['pending_update'] ?? null) !== null) {
            throw new PaymentFailedException('The proration payment for the plan change failed.');
        }

        return self::subscriptionFrom($subscription);
    }

    public function cancelSubscriptionNow(string $subscriptionId): void
    {
        $this->call(fn () => $this->client->subscriptions->cancel($subscriptionId));
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $session = $this->call(fn () => $this->client->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]));

        return $session->url;
    }

    public function createConnectAccount(Creator $creator): string
    {
        // Stripe no longer allows v1 account creation for new Connect integrations. A v2 "recipient" account with
        // the Express dashboard receives our destination-charge transfers; the platform carries fees and losses
        // (as recommended for destination charges). v1 retrieve and login links still work on these accounts.
        $account = $this->call(fn () => $this->client->v2->core->accounts->create([
            'contact_email' => $creator->user->email,
            'display_name' => $creator->display_name,
            'identity' => ['country' => 'jp'],
            'dashboard' => 'express',
            'defaults' => ['responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application']],
            'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
            'metadata' => ['creator_id' => (string) $creator->id],
        ], ['idempotency_key' => "creator-{$creator->id}-account-v2"]));

        return $account->id;
    }

    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $link = $this->call(fn () => $this->client->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => ['refresh_url' => $refreshUrl, 'return_url' => $returnUrl],
            ],
        ]));

        return $link->url;
    }

    public function createDashboardLoginLink(string $accountId): string
    {
        return $this->call(fn () => $this->client->accounts->createLoginLink($accountId))->url;
    }

    public function retrieveAccount(string $accountId): AccountData
    {
        $account = $this->call(fn () => $this->client->accounts->retrieve($accountId))->toArray();

        return new AccountData(
            id: (string) $account['id'],
            payoutsEnabled: ($account['payouts_enabled'] ?? false) === true && ($account['capabilities']['transfers'] ?? null) === 'active',
            detailsSubmitted: ($account['details_submitted'] ?? false) === true,
        );
    }

    public function retrieveInvoice(string $id): InvoiceData
    {
        return self::invoiceFrom($this->call(fn () => $this->client->invoices->retrieve($id)));
    }

    /**
     * The period end moved from the subscription to its items in newer API versions; both are read.
     */
    public static function subscriptionFrom(Subscription $subscription): SubscriptionData
    {
        $data = $subscription->toArray();
        $item = $data['items']['data'][0] ?? null;
        $periodEnd = $item['current_period_end'] ?? $data['current_period_end'] ?? null;

        return new SubscriptionData(
            id: (string) $data['id'],
            customerId: self::idOf($data['customer'] ?? null) ?? '',
            status: (string) ($data['status'] ?? ''),
            itemId: isset($item['id']) ? (string) $item['id'] : null,
            priceId: isset($item['price']['id']) ? (string) $item['price']['id'] : null,
            unitAmount: isset($item['price']['unit_amount']) ? (int) $item['price']['unit_amount'] : null,
            currentPeriodEnd: $periodEnd === null ? null : CarbonImmutable::createFromTimestamp((int) $periodEnd, config('app.timezone')),
            cancelAtPeriodEnd: ($data['cancel_at_period_end'] ?? false) === true,
            metadata: self::stringMap($data['metadata'] ?? []),
        );
    }

    /**
     * The subscription moved under parent.subscription_details in newer API versions; the old field is the fallback.
     */
    public static function invoiceFrom(Invoice $invoice): InvoiceData
    {
        $data = $invoice->toArray();
        $paidAt = $data['status_transitions']['paid_at'] ?? null;
        $subscription = $data['parent']['subscription_details']['subscription'] ?? $data['subscription'] ?? null;

        return new InvoiceData(
            id: (string) $data['id'],
            subscriptionId: self::idOf($subscription),
            customerId: self::idOf($data['customer'] ?? null) ?? '',
            status: (string) ($data['status'] ?? ''),
            amountPaid: (int) ($data['amount_paid'] ?? 0),
            paidAt: $paidAt === null ? null : CarbonImmutable::createFromTimestamp((int) $paidAt, config('app.timezone')),
        );
    }

    private static function sessionFrom(StripeObject $session): CheckoutSessionData
    {
        $data = $session->toArray();

        return new CheckoutSessionData(
            id: (string) $data['id'],
            url: (string) ($data['url'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            subscriptionId: self::idOf($data['subscription'] ?? null),
            customerId: self::idOf($data['customer'] ?? null),
            metadata: self::stringMap($data['metadata'] ?? []),
        );
    }

    /**
     * Expandable fields come back as an id or as the expanded object.
     */
    private static function idOf(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        return is_array($value) ? array_map(strval(...), $value) : [];
    }

    /**
     * Run one Stripe call, turning provider errors into BillingException.
     *
     * stripe-php raises advisory "Stripe-Notice" headers as E_USER_WARNING after a successful
     * request; Laravel would turn that into an exception and lose the response, so log it instead.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function call(Closure $call): mixed
    {
        set_error_handler(function (int $level, string $message): bool {
            Log::warning('billing.stripe_notice', ['message' => $message]);

            return true;
        }, E_USER_WARNING);

        try {
            return $call();
        } catch (ApiErrorException $e) {
            Log::error('Stripe API error', [
                'message' => $e->getMessage(),
                'request_id' => $e->getRequestId(),
                'http_status' => $e->getHttpStatus(),
            ]);

            throw new BillingException($e->getMessage(), previous: $e);
        } finally {
            restore_error_handler();
        }
    }
}
