<?php

namespace App\Billing;

use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\InvoiceData;
use App\Billing\Data\ShippingDetails;
use App\Billing\Data\SubscriptionData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * The state of the fake Stripe: one array under one cache key (an array cache in tests, Redis in local development),
 * with the lookups that fail like Stripe on an unknown id and the conversions to the gateway's data objects.
 *
 * @phpstan-type State array{
 *     customers: array<string, array<string, mixed>>,
 *     prices: array<string, array{product_id: string, plan_id: int, amount: int}>,
 *     sessions: array<string, array<string, mixed>>,
 *     subscriptions: array<string, array<string, mixed>>,
 *     invoices: array<string, array<string, mixed>>,
 *     charges: array<string, array{invoice_id: string|null, amount: int, amount_refunded: int, disputed: bool, payment_intent_id?: string}>,
 *     paymentIntents: array<string, array{status: string, amount: int, charge_id: string, refunded: bool}>,
 *     accounts: array<string, array{payouts_enabled: bool, details_submitted: bool}>,
 *     cancelledNow: list<string>,
 *     refundKeys: array<string, string>,
 *     failPayments: bool,
 * }
 */
final class FakeBillingStore
{
    private const KEY = 'fake-billing:state';

    public function __construct(private readonly Repository $cache) {}

    /**
     * @return State
     */
    public function state(): array
    {
        /** @var State|null $state */
        $state = $this->cache->get(self::KEY);

        return $state === null ? [
            'customers' => [],
            'prices' => [],
            'sessions' => [],
            'subscriptions' => [],
            'invoices' => [],
            'charges' => [],
            'paymentIntents' => [],
            'accounts' => [],
            'cancelledNow' => [],
            'refundKeys' => [],
            'failPayments' => false,
        ] : $state + ['paymentIntents' => [], 'refundKeys' => []];
    }

    /**
     * @param  State  $state
     */
    public function save(array $state): void
    {
        $this->cache->forever(self::KEY, $state);
    }

    public function newId(string $prefix): string
    {
        return $prefix.'_fake_'.Str::lower(Str::random(14));
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    public function customer(array $state, string $id): array
    {
        return $state['customers'][$id] ?? throw new BillingException("Unknown customer {$id}.");
    }

    /**
     * @param  State  $state
     * @return array{product_id: string, plan_id: int, amount: int}
     */
    public function price(array $state, string $id): array
    {
        return $state['prices'][$id] ?? throw new BillingException("Unknown price {$id}.");
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    public function session(array $state, string $id): array
    {
        return $state['sessions'][$id] ?? throw new BillingException("Unknown checkout session {$id}.");
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    public function subscription(array $state, string $id): array
    {
        return $state['subscriptions'][$id] ?? throw new BillingException("Unknown subscription {$id}.");
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    public function invoice(array $state, string $id): array
    {
        return $state['invoices'][$id] ?? throw new BillingException("Unknown invoice {$id}.");
    }

    /**
     * @param  State  $state
     * @return array{invoice_id: string|null, amount: int, amount_refunded: int, disputed: bool, payment_intent_id?: string}
     */
    public function charge(array $state, string $id): array
    {
        return $state['charges'][$id] ?? throw new BillingException("Unknown charge {$id}.");
    }

    /**
     * @param  State  $state
     * @return array{payouts_enabled: bool, details_submitted: bool}
     */
    public function account(array $state, string $id): array
    {
        return $state['accounts'][$id] ?? throw new BillingException("Unknown account {$id}.");
    }

    /**
     * Add a paid invoice with its charge (and mark it as the subscription's latest) to the given state; the caller saves.
     *
     * @param  State  $state
     */
    public function addInvoice(array &$state, string $subscriptionId, string $customerId, int $amount): string
    {
        $id = $this->newId('in');
        $chargeId = $this->newId('ch');
        $state['invoices'][$id] = [
            'id' => $id,
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => 'paid',
            'amount_paid' => $amount,
            'paid_at' => CarbonImmutable::now()->getTimestamp(),
            'charge_id' => $chargeId,
            'refunded' => false,
        ];
        $state['charges'][$chargeId] = ['invoice_id' => $id, 'amount' => $amount, 'amount_refunded' => 0, 'disputed' => false];
        $state['subscriptions'][$subscriptionId]['latest_invoice_id'] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function sessionData(array $session): CheckoutSessionData
    {
        $id = (string) $session['id'];

        return new CheckoutSessionData(
            id: $id,
            url: url('/dev/fake-checkout/'.$id),
            status: (string) $session['status'],
            subscriptionId: $session['subscription_id'] === null ? null : (string) $session['subscription_id'],
            customerId: (string) $session['customer_id'],
            metadata: $session['metadata'],
            paymentStatus: $session['payment_status'] ?? null,
            paymentIntentId: $session['payment_intent_id'] ?? null,
            shipping: isset($session['shipping']) ? new ShippingDetails(...$session['shipping']) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    public function subscriptionData(array $subscription): SubscriptionData
    {
        return new SubscriptionData(
            id: (string) $subscription['id'],
            customerId: (string) $subscription['customer_id'],
            status: (string) $subscription['status'],
            itemId: (string) $subscription['item_id'],
            priceId: (string) $subscription['price_id'],
            unitAmount: (int) $subscription['unit_amount'],
            currentPeriodEnd: CarbonImmutable::createFromTimestamp((int) $subscription['period_end'], config('app.timezone')),
            cancelAtPeriodEnd: (bool) $subscription['cancel_at_period_end'],
            metadata: $subscription['metadata'],
            latestInvoiceId: $subscription['latest_invoice_id'] === null ? null : (string) $subscription['latest_invoice_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function invoiceData(array $invoice): InvoiceData
    {
        return new InvoiceData(
            id: (string) $invoice['id'],
            subscriptionId: $invoice['subscription_id'],
            customerId: (string) $invoice['customer_id'],
            status: (string) $invoice['status'],
            amountPaid: (int) $invoice['amount_paid'],
            paidAt: CarbonImmutable::createFromTimestamp((int) $invoice['paid_at'], config('app.timezone')),
        );
    }
}
