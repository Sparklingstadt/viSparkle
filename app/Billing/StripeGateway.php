<?php

namespace App\Billing;

use App\Billing\Data\AccountData;
use App\Billing\Data\ChargeData;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\InvoiceData;
use App\Billing\Data\LineItem;
use App\Billing\Data\PaymentCheckoutRequest;
use App\Billing\Data\PriceRef;
use App\Billing\Data\RefundData;
use App\Billing\Data\ShippingDetails;
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

    /**
     * Whether the configured secret moves real money (sk_live_ or restricted rk_live_ keys); test keys never do.
     */
    public static function usesLiveKeys(): bool
    {
        return str_contains((string) config('fanbox.billing.stripe.secret'), '_live_');
    }

    public function createCustomer(User $user): string
    {
        $customer = $this->call(fn () => $this->client->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string) $user->id],
        ], ['idempotency_key' => $this->idempotencyKey("user-{$user->id}-customer")]));

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
        ], ['idempotency_key' => $this->idempotencyKey("plan-{$plan->id}-product")]));

        $price = $this->call(fn () => $this->client->prices->create([
            'product' => $product->id,
            'currency' => 'jpy',
            'unit_amount' => $plan->price,
            'recurring' => ['interval' => 'month'],
            'tax_behavior' => 'inclusive',
        ], ['idempotency_key' => $this->idempotencyKey("plan-{$plan->id}-price")]));

        $plan->forceFill(['stripe_product_id' => $product->id, 'stripe_price_id' => $price->id])->save();

        return new PriceRef($product->id, $price->id);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSessionData
    {
        $session = $this->call(fn () => $this->client->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $request->customerId,
            'line_items' => [['price' => $request->priceId, 'quantity' => 1]],
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

    public function createPaymentCheckoutSession(PaymentCheckoutRequest $request): CheckoutSessionData
    {
        $session = $this->call(fn () => $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $request->customerId,
            'line_items' => array_map(fn (LineItem $item) => [
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $item->unitAmount,
                    'product_data' => ['name' => $item->name],
                ],
                'quantity' => $item->quantity,
            ], $request->lineItems),
            'locale' => 'ja',
            'shipping_address_collection' => ['allowed_countries' => ['JP']],
            'phone_number_collection' => ['enabled' => true],
            'expires_at' => $request->expiresAt->getTimestamp(),
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => $request->metadata,
            'payment_intent_data' => [
                'application_fee_amount' => $request->applicationFeeAmount,
                'transfer_data' => ['destination' => $request->destinationAccountId],
                'metadata' => $request->metadata,
            ],
        ], ['idempotency_key' => $this->idempotencyKey($request->idempotencyKey)]));

        return self::sessionFrom($session);
    }

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): RefundData
    {
        // No amount: a full refund, with the transfer reversal and fee refund coming back in full too.
        $refund = $this->call(fn () => $this->client->refunds->create([
            'payment_intent' => $paymentIntentId,
            'reverse_transfer' => true,
            'refund_application_fee' => true,
        ], ['idempotency_key' => $this->idempotencyKey($idempotencyKey)]))->toArray();

        return new RefundData((string) $refund['id'], (int) ($refund['amount'] ?? 0));
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
        ], ['idempotency_key' => $this->idempotencyKey("creator-{$creator->id}-account-v2")]));

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

    public function refundInvoice(string $invoiceId): RefundData
    {
        $target = $this->refundTarget($invoiceId);

        // No amount: a full refund. The transfer reversal and fee refund are proportional, so both come back in full too.
        $refund = $this->call(fn () => $this->client->refunds->create([
            ...$target,
            'reverse_transfer' => true,
            'refund_application_fee' => true,
            'metadata' => ['invoice' => $invoiceId],
        ], ['idempotency_key' => $this->idempotencyKey("refund-invoice-{$invoiceId}")]))->toArray();

        return new RefundData((string) $refund['id'], (int) ($refund['amount'] ?? 0));
    }

    /**
     * Charges no longer carry their invoice in newer API versions; it is found through the invoice payment of the
     * charge's PaymentIntent. The legacy `invoice` field is read first when present.
     */
    public function retrieveCharge(string $chargeId): ChargeData
    {
        $charge = $this->call(fn () => $this->client->charges->retrieve($chargeId))->toArray();
        $invoiceId = self::idOf($charge['invoice'] ?? null);
        $paymentIntentId = self::idOf($charge['payment_intent'] ?? null);

        if ($invoiceId === null && $paymentIntentId !== null) {
            $payments = $this->call(fn () => $this->client->invoicePayments->all([
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId],
            ]))->toArray();
            $invoiceId = self::idOf($payments['data'][0]['invoice'] ?? null);
        }

        return new ChargeData(
            id: (string) $charge['id'],
            invoiceId: $invoiceId,
            amountRefunded: (int) ($charge['amount_refunded'] ?? 0),
            disputed: ($charge['disputed'] ?? false) === true,
            paymentIntentId: $paymentIntentId,
        );
    }

    /**
     * The period end moved from the subscription to its items in newer API versions; both are read.
     */
    public function retrievePaymentIntentStatus(string $paymentIntentId): string
    {
        $paymentIntent = $this->call(fn () => $this->client->paymentIntents->retrieve($paymentIntentId))->toArray();

        return (string) ($paymentIntent['status'] ?? '');
    }

    public function retrieveLatestChargeId(string $paymentIntentId): ?string
    {
        $paymentIntent = $this->call(fn () => $this->client->paymentIntents->retrieve($paymentIntentId))->toArray();

        return self::idOf($paymentIntent['latest_charge'] ?? null);
    }

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
            latestInvoiceId: self::idOf($data['latest_invoice'] ?? null),
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

    /**
     * What a refund of the invoice targets. Invoices no longer carry `payment_intent`/`charge` in newer API versions; their
     * payments are listed under /v1/invoice_payments. The legacy invoice fields are the fallback.
     *
     * @return array{payment_intent: string}|array{charge: string}
     *
     * @throws BillingException when the invoice has no paid payment
     */
    private function refundTarget(string $invoiceId): array
    {
        $payments = $this->call(fn () => $this->client->invoicePayments->all(['invoice' => $invoiceId]))->toArray();

        foreach ($payments['data'] ?? [] as $payment) {
            if (($payment['status'] ?? null) !== 'paid') {
                continue;
            }

            $target = self::paymentTarget($payment['payment'] ?? []);

            if ($target !== null) {
                return $target;
            }
        }

        $invoice = $this->call(fn () => $this->client->invoices->retrieve($invoiceId))->toArray();

        return self::paymentTarget($invoice) ?? throw new BillingException("Invoice {$invoiceId} has no payment to refund.");
    }

    /**
     * @return array{payment_intent: string}|array{charge: string}|null
     */
    private static function paymentTarget(mixed $payment): ?array
    {
        if (! is_array($payment)) {
            return null;
        }

        $paymentIntentId = self::idOf($payment['payment_intent'] ?? null);

        if ($paymentIntentId !== null) {
            return ['payment_intent' => $paymentIntentId];
        }

        $chargeId = self::idOf($payment['charge'] ?? null);

        return $chargeId === null ? null : ['charge' => $chargeId];
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
            paymentStatus: isset($data['payment_status']) ? (string) $data['payment_status'] : null,
            paymentIntentId: self::idOf($data['payment_intent'] ?? null),
            shipping: self::shippingFrom($data),
        );
    }

    /**
     * Shipping moved to collected_information in newer API versions; the old top-level field is the fallback.
     *
     * @param  array<string, mixed>  $session
     */
    private static function shippingFrom(array $session): ?ShippingDetails
    {
        $shipping = $session['collected_information']['shipping_details'] ?? $session['shipping_details'] ?? null;

        if (! is_array($shipping)) {
            return null;
        }

        $address = is_array($shipping['address'] ?? null) ? $shipping['address'] : [];
        $parts = array_filter(
            array_map(fn (string $key) => trim((string) ($address[$key] ?? '')), ['state', 'city', 'line1', 'line2']),
            fn (string $part) => $part !== '',
        );
        $phone = $session['customer_details']['phone'] ?? null;

        return new ShippingDetails(
            name: (string) ($shipping['name'] ?? ''),
            postalCode: (string) ($address['postal_code'] ?? ''),
            address: implode(' ', $parts),
            phone: is_string($phone) && $phone !== '' ? $phone : null,
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
     * Idempotency keys live per Stripe account, and several app environments (local, production) may share one test-mode
     * account with overlapping database ids, so namespace every key by environment.
     */
    private function idempotencyKey(string $key): string
    {
        return config('fanbox.billing.idempotency_prefix').':'.$key;
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
