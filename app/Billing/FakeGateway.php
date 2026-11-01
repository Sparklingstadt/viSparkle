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
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * In-memory stand-in for Stripe, for tests (array cache) and local development (Redis).
 * The whole state lives under one cache key; prices are the only thing also saved on the plan.
 *
 * @phpstan-type State array{
 *     customers: array<string, array<string, mixed>>,
 *     prices: array<string, array{product_id: string, plan_id: int, amount: int}>,
 *     sessions: array<string, array<string, mixed>>,
 *     subscriptions: array<string, array<string, mixed>>,
 *     invoices: array<string, array<string, mixed>>,
 *     accounts: array<string, array{payouts_enabled: bool, details_submitted: bool}>,
 *     cancelledNow: list<string>,
 *     failPayments: bool,
 * }
 */
class FakeGateway implements PaymentGateway
{
    private const KEY = 'fake-billing:state';

    public function __construct(private readonly Repository $cache) {}

    public function createCustomer(User $user): string
    {
        $state = $this->state();
        $id = $this->newId('cus');
        $state['customers'][$id] = ['user_id' => $user->id];
        $this->save($state);

        return $id;
    }

    public function ensurePrice(Plan $plan): PriceRef
    {
        $state = $this->state();

        if ($plan->stripe_product_id !== null && $plan->stripe_price_id !== null) {
            // Make sure this fake instance knows the price (e.g. after the cache was cleared).
            if (! isset($state['prices'][$plan->stripe_price_id])) {
                $state['prices'][$plan->stripe_price_id] = [
                    'product_id' => $plan->stripe_product_id,
                    'plan_id' => $plan->id,
                    'amount' => $plan->price,
                ];
                $this->save($state);
            }

            return new PriceRef($plan->stripe_product_id, $plan->stripe_price_id);
        }

        $productId = $this->newId('prod');
        $priceId = $this->newId('price');
        $state['prices'][$priceId] = ['product_id' => $productId, 'plan_id' => $plan->id, 'amount' => $plan->price];
        $this->save($state);

        $plan->forceFill(['stripe_product_id' => $productId, 'stripe_price_id' => $priceId])->save();

        return new PriceRef($productId, $priceId);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSessionData
    {
        $state = $this->state();
        $price = $this->price($state, $request->priceId);
        $this->customer($state, $request->customerId);

        $id = $this->newId('cs');
        $state['sessions'][$id] = [
            'id' => $id,
            'status' => 'open',
            'customer_id' => $request->customerId,
            'price_id' => $request->priceId,
            'amount' => $price['amount'],
            'plan_id' => $price['plan_id'],
            'metadata' => $request->metadata,
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'expires_at' => $request->expiresAt->getTimestamp(),
            'subscription_id' => null,
        ];
        $this->save($state);

        return $this->sessionData($state['sessions'][$id]);
    }

    public function retrieveCheckoutSession(string $id): CheckoutSessionData
    {
        return $this->sessionData($this->session($this->state(), $id));
    }

    public function expireCheckoutSession(string $id): void
    {
        $state = $this->state();
        $session = $this->session($state, $id);

        if ($session['status'] === 'open') {
            $state['sessions'][$id]['status'] = 'expired';
            $this->save($state);
        }
    }

    public function retrieveSubscription(string $id): SubscriptionData
    {
        return $this->subscriptionData($this->subscription($this->state(), $id));
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): SubscriptionData
    {
        $state = $this->state();
        $this->subscription($state, $subscriptionId);
        $state['subscriptions'][$subscriptionId]['cancel_at_period_end'] = $cancel;
        $this->save($state);

        return $this->subscriptionData($state['subscriptions'][$subscriptionId]);
    }

    public function changeSubscriptionPrice(string $subscriptionId, string $itemId, string $priceId, bool $chargeProrationNow): SubscriptionData
    {
        $state = $this->state();
        $subscription = $this->subscription($state, $subscriptionId);
        $price = $this->price($state, $priceId);

        $difference = $price['amount'] - (int) $subscription['unit_amount'];

        if ($chargeProrationNow && $difference > 0) {
            if ($state['failPayments']) {
                throw new PaymentFailedException('The proration payment failed.');
            }

            $this->addInvoice($state, $subscriptionId, (string) $subscription['customer_id'], $difference);
        }

        $state['subscriptions'][$subscriptionId]['price_id'] = $priceId;
        $state['subscriptions'][$subscriptionId]['unit_amount'] = $price['amount'];
        $this->save($state);

        return $this->subscriptionData($state['subscriptions'][$subscriptionId]);
    }

    public function cancelSubscriptionNow(string $subscriptionId): void
    {
        $state = $this->state();
        $this->subscription($state, $subscriptionId);
        $state['subscriptions'][$subscriptionId]['status'] = 'canceled';
        $state['cancelledNow'][] = $subscriptionId;
        $this->save($state);
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $this->customer($this->state(), $customerId);

        return $returnUrl;
    }

    public function createConnectAccount(Creator $creator): string
    {
        $state = $this->state();
        $id = $this->newId('acct');
        $state['accounts'][$id] = ['payouts_enabled' => false, 'details_submitted' => false];
        $this->save($state);

        return $id;
    }

    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $state = $this->state();
        $this->account($state, $accountId);
        $state['accounts'][$accountId] = ['payouts_enabled' => true, 'details_submitted' => true];
        $this->save($state);

        return $returnUrl;
    }

    public function createDashboardLoginLink(string $accountId): string
    {
        $this->account($this->state(), $accountId);

        return url('/creator/earnings');
    }

    public function retrieveAccount(string $accountId): AccountData
    {
        $account = $this->account($this->state(), $accountId);

        return new AccountData($accountId, $account['payouts_enabled'], $account['details_submitted']);
    }

    public function retrieveInvoice(string $id): InvoiceData
    {
        return $this->invoiceData($this->invoice($this->state(), $id));
    }

    /*
    | Test / dev controls (not part of PaymentGateway)
    */

    /**
     * Turn an open checkout session into an active subscription with a paid first invoice.
     */
    public function completeCheckout(string $sessionId): SubscriptionData
    {
        $state = $this->state();
        $session = $this->session($state, $sessionId);

        if ($session['status'] !== 'open') {
            throw new BillingException("Checkout session {$sessionId} is {$session['status']}.");
        }

        $subscriptionId = $this->newId('sub');
        $state['subscriptions'][$subscriptionId] = [
            'id' => $subscriptionId,
            'customer_id' => $session['customer_id'],
            'status' => 'active',
            'item_id' => $this->newId('si'),
            'price_id' => $session['price_id'],
            'unit_amount' => $session['amount'],
            'period_end' => CarbonImmutable::now()->addMonth()->getTimestamp(),
            'cancel_at_period_end' => false,
            'metadata' => $session['metadata'],
            'latest_invoice_id' => null,
        ];
        $this->addInvoice($state, $subscriptionId, (string) $session['customer_id'], (int) $session['amount']);

        $state['sessions'][$sessionId]['status'] = 'complete';
        $state['sessions'][$sessionId]['subscription_id'] = $subscriptionId;
        $this->save($state);

        return $this->subscriptionData($state['subscriptions'][$subscriptionId]);
    }

    /**
     * While set, upgrades fail and renewals leave the subscription past_due.
     */
    public function failPayments(bool $fail = true): void
    {
        $state = $this->state();
        $state['failPayments'] = $fail;
        $this->save($state);
    }

    /**
     * Simulate the end of a billing period: end a scheduled cancellation, fail the renewal, or renew and invoice.
     */
    public function advancePeriod(string $subscriptionId): ?InvoiceData
    {
        $state = $this->state();
        $subscription = $this->subscription($state, $subscriptionId);

        if ($subscription['status'] === 'canceled') {
            return null;
        }

        if ($subscription['cancel_at_period_end']) {
            $state['subscriptions'][$subscriptionId]['status'] = 'canceled';
            $this->save($state);

            return null;
        }

        if ($state['failPayments']) {
            $state['subscriptions'][$subscriptionId]['status'] = 'past_due';
            $this->save($state);

            return null;
        }

        $state['subscriptions'][$subscriptionId]['status'] = 'active';
        $state['subscriptions'][$subscriptionId]['period_end'] = CarbonImmutable::createFromTimestamp((int) $subscription['period_end'])->addMonth()->getTimestamp();
        $invoiceId = $this->addInvoice($state, $subscriptionId, (string) $subscription['customer_id'], (int) $subscription['unit_amount']);
        $this->save($state);

        return $this->invoiceData($state['invoices'][$invoiceId]);
    }

    public function setSubscriptionStatus(string $subscriptionId, string $status): void
    {
        $state = $this->state();
        $this->subscription($state, $subscriptionId);
        $state['subscriptions'][$subscriptionId]['status'] = $status;
        $this->save($state);
    }

    /**
     * Set the state of a connected account, registering it when unknown (e.g. the seeded demo creator's).
     */
    public function setAccount(string $accountId, bool $payoutsEnabled, bool $detailsSubmitted): void
    {
        $state = $this->state();
        $state['accounts'][$accountId] = ['payouts_enabled' => $payoutsEnabled, 'details_submitted' => $detailsSubmitted];
        $this->save($state);
    }

    /**
     * What the dev checkout page shows for a session, or null when it does not exist.
     *
     * @return array{amount: int, plan_id: int, user_id: string|null, status: string, success_url: string, cancel_url: string}|null
     */
    public function describeSession(string $id): ?array
    {
        $session = $this->state()['sessions'][$id] ?? null;

        if ($session === null) {
            return null;
        }

        return [
            'amount' => (int) $session['amount'],
            'plan_id' => (int) $session['plan_id'],
            'user_id' => isset($session['metadata']['user_id']) ? (string) $session['metadata']['user_id'] : null,
            'status' => (string) $session['status'],
            'success_url' => (string) $session['success_url'],
            'cancel_url' => (string) $session['cancel_url'],
        ];
    }

    public function latestInvoiceId(string $subscriptionId): ?string
    {
        $invoiceId = $this->state()['subscriptions'][$subscriptionId]['latest_invoice_id'] ?? null;

        return $invoiceId === null ? null : (string) $invoiceId;
    }

    /**
     * @return list<string>
     */
    public function cancelledNow(): array
    {
        return $this->state()['cancelledNow'];
    }

    /*
    | Internals
    */

    /**
     * @return State
     */
    private function state(): array
    {
        /** @var State|null $state */
        $state = $this->cache->get(self::KEY);

        return $state ?? [
            'customers' => [],
            'prices' => [],
            'sessions' => [],
            'subscriptions' => [],
            'invoices' => [],
            'accounts' => [],
            'cancelledNow' => [],
            'failPayments' => false,
        ];
    }

    /**
     * @param  State  $state
     */
    private function save(array $state): void
    {
        $this->cache->forever(self::KEY, $state);
    }

    private function newId(string $prefix): string
    {
        return $prefix.'_fake_'.Str::lower(Str::random(14));
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    private function customer(array $state, string $id): array
    {
        return $state['customers'][$id] ?? throw new BillingException("Unknown customer {$id}.");
    }

    /**
     * @param  State  $state
     * @return array{product_id: string, plan_id: int, amount: int}
     */
    private function price(array $state, string $id): array
    {
        return $state['prices'][$id] ?? throw new BillingException("Unknown price {$id}.");
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    private function session(array $state, string $id): array
    {
        return $state['sessions'][$id] ?? throw new BillingException("Unknown checkout session {$id}.");
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    private function subscription(array $state, string $id): array
    {
        return $state['subscriptions'][$id] ?? throw new BillingException("Unknown subscription {$id}.");
    }

    /**
     * @param  State  $state
     * @return array<string, mixed>
     */
    private function invoice(array $state, string $id): array
    {
        return $state['invoices'][$id] ?? throw new BillingException("Unknown invoice {$id}.");
    }

    /**
     * @param  State  $state
     * @return array{payouts_enabled: bool, details_submitted: bool}
     */
    private function account(array $state, string $id): array
    {
        return $state['accounts'][$id] ?? throw new BillingException("Unknown account {$id}.");
    }

    /**
     * Add a paid invoice (and mark it as the subscription's latest) to the given state; the caller saves.
     *
     * @param  State  $state
     */
    private function addInvoice(array &$state, string $subscriptionId, string $customerId, int $amount): string
    {
        $id = $this->newId('in');
        $state['invoices'][$id] = [
            'id' => $id,
            'subscription_id' => $subscriptionId,
            'customer_id' => $customerId,
            'status' => 'paid',
            'amount_paid' => $amount,
            'paid_at' => CarbonImmutable::now()->getTimestamp(),
        ];
        $state['subscriptions'][$subscriptionId]['latest_invoice_id'] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function sessionData(array $session): CheckoutSessionData
    {
        $id = (string) $session['id'];

        return new CheckoutSessionData(
            id: $id,
            url: url('/dev/fake-checkout/'.$id),
            status: (string) $session['status'],
            subscriptionId: $session['subscription_id'] === null ? null : (string) $session['subscription_id'],
            customerId: (string) $session['customer_id'],
            metadata: $session['metadata'],
        );
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionData(array $subscription): SubscriptionData
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
        );
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function invoiceData(array $invoice): InvoiceData
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
