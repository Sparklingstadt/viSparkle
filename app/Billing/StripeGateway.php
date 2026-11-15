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
use App\Billing\Data\SubscriptionData;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Invoice;
use Stripe\StripeClient;

/**
 * Production gateway backed by Stripe. Stripe objects never leave this class; API-version differences are absorbed here.
 */
class StripeGateway implements PaymentGateway
{
    private const CURRENCY = 'jpy';

    private const LOCALE = 'ja';

    /** Goods ship within Japan only. */
    private const SHIPPING_COUNTRIES = ['JP'];

    /** The country of the creators' payout accounts. */
    private const ACCOUNT_COUNTRY = 'jp';

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
            'currency' => self::CURRENCY,
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
            'locale' => self::LOCALE,
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

        return StripeObjectMapper::checkoutSession($session);
    }

    public function retrieveCheckoutSession(string $id): CheckoutSessionData
    {
        return StripeObjectMapper::checkoutSession($this->call(fn () => $this->client->checkout->sessions->retrieve($id)));
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
                    'currency' => self::CURRENCY,
                    'unit_amount' => $item->unitAmount,
                    'product_data' => ['name' => $item->name],
                ],
                'quantity' => $item->quantity,
            ], $request->lineItems),
            'locale' => self::LOCALE,
            ...($request->collectShipping ? [
                'shipping_address_collection' => ['allowed_countries' => self::SHIPPING_COUNTRIES],
                'phone_number_collection' => ['enabled' => true],
            ] : []),
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

        return StripeObjectMapper::checkoutSession($session);
    }

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): RefundData
    {
        return $this->createFullRefund(['payment_intent' => $paymentIntentId], $idempotencyKey);
    }

    public function retrieveSubscription(string $id): SubscriptionData
    {
        return StripeObjectMapper::subscription($this->call(fn () => $this->client->subscriptions->retrieve($id)));
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): SubscriptionData
    {
        return StripeObjectMapper::subscription($this->call(
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

        return StripeObjectMapper::subscription($subscription);
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
            'identity' => ['country' => self::ACCOUNT_COUNTRY],
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
        return StripeObjectMapper::invoice($this->call(fn () => $this->client->invoices->retrieve($id)));
    }

    public function refundInvoice(string $invoiceId): RefundData
    {
        return $this->createFullRefund($this->refundTarget($invoiceId), "refund-invoice-{$invoiceId}", ['invoice' => $invoiceId]);
    }

    /**
     * Charges no longer carry their invoice in newer API versions; it is found through the invoice payment of the
     * charge's PaymentIntent. The legacy `invoice` field is read first when present.
     */
    public function retrieveCharge(string $chargeId): ChargeData
    {
        $charge = $this->call(fn () => $this->client->charges->retrieve($chargeId))->toArray();
        $invoiceId = StripeObjectMapper::idOf($charge['invoice'] ?? null);
        $paymentIntentId = StripeObjectMapper::idOf($charge['payment_intent'] ?? null);

        if ($invoiceId === null && $paymentIntentId !== null) {
            $payments = $this->call(fn () => $this->client->invoicePayments->all([
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntentId],
            ]))->toArray();
            $invoiceId = StripeObjectMapper::idOf($payments['data'][0]['invoice'] ?? null);
        }

        return new ChargeData(
            id: (string) $charge['id'],
            invoiceId: $invoiceId,
            amountRefunded: (int) ($charge['amount_refunded'] ?? 0),
            disputed: ($charge['disputed'] ?? false) === true,
            paymentIntentId: $paymentIntentId,
        );
    }

    public function retrievePaymentIntentStatus(string $paymentIntentId): string
    {
        $paymentIntent = $this->call(fn () => $this->client->paymentIntents->retrieve($paymentIntentId))->toArray();

        return (string) ($paymentIntent['status'] ?? '');
    }

    public function retrieveLatestChargeId(string $paymentIntentId): ?string
    {
        $paymentIntent = $this->call(fn () => $this->client->paymentIntents->retrieve($paymentIntentId))->toArray();

        return StripeObjectMapper::idOf($paymentIntent['latest_charge'] ?? null);
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

            $target = StripeObjectMapper::paymentTarget($payment['payment'] ?? []);

            if ($target !== null) {
                return $target;
            }
        }

        $invoice = $this->call(fn () => $this->client->invoices->retrieve($invoiceId))->toArray();

        return StripeObjectMapper::paymentTarget($invoice) ?? throw new BillingException("Invoice {$invoiceId} has no payment to refund.");
    }

    /**
     * Refunds a payment in full. With no amount, the transfer reversal and the fee refund are proportional, so both come
     * back in full too.
     *
     * @param  array{payment_intent: string}|array{charge: string}  $target
     * @param  array<string, string>  $metadata
     */
    private function createFullRefund(array $target, string $idempotencyKey, array $metadata = []): RefundData
    {
        $refund = $this->call(fn () => $this->client->refunds->create([
            ...$target,
            'reverse_transfer' => true,
            'refund_application_fee' => true,
            ...($metadata === [] ? [] : ['metadata' => $metadata]),
        ], ['idempotency_key' => $this->idempotencyKey($idempotencyKey)]))->toArray();

        return new RefundData((string) $refund['id'], (int) ($refund['amount'] ?? 0));
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
