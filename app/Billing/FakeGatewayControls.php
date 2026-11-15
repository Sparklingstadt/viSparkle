<?php

namespace App\Billing;

use App\Billing\Data\InvoiceData;
use App\Billing\Data\SubscriptionData;
use App\Models\Creator;
use Carbon\CarbonImmutable;

/**
 * The controls tests and the local fake checkout use to play Stripe's part (completing a checkout, settling a delayed
 * payment, renewing a period, failing payments) and to look into the fake's state. Not part of PaymentGateway.
 *
 * @phpstan-import-type State from FakeBillingStore
 */
trait FakeGatewayControls
{
    /** The shipping address a fake buyer enters at checkout: [name, postal code, address, phone]. */
    private const TEST_SHIPPING = ['テスト 太郎', '100-0001', '東京都 千代田区 千代田1-1', '09000000000'];

    /**
     * Turn an open checkout session into an active subscription with a paid first invoice.
     */
    public function completeCheckout(string $sessionId): SubscriptionData
    {
        $state = $this->store->state();
        $session = $this->store->session($state, $sessionId);

        if ($session['status'] !== 'open') {
            throw new BillingException("Checkout session {$sessionId} is {$session['status']}.");
        }

        $subscriptionId = $this->store->newId('sub');
        $state['subscriptions'][$subscriptionId] = [
            'id' => $subscriptionId,
            'customer_id' => $session['customer_id'],
            'status' => 'active',
            'item_id' => $this->store->newId('si'),
            'price_id' => $session['price_id'],
            'unit_amount' => $session['amount'],
            'period_end' => CarbonImmutable::now()->addMonth()->getTimestamp(),
            'cancel_at_period_end' => false,
            'metadata' => $session['metadata'],
            'latest_invoice_id' => null,
        ];
        $this->store->addInvoice($state, $subscriptionId, (string) $session['customer_id'], (int) $session['amount']);

        $state['sessions'][$sessionId]['status'] = 'complete';
        $state['sessions'][$sessionId]['subscription_id'] = $subscriptionId;
        $this->store->save($state);

        return $this->store->subscriptionData($state['subscriptions'][$subscriptionId]);
    }

    /**
     * Turn an open payment checkout session into a complete one with a payment intent and charge.
     * With $paid = false the payment is still pending (a delayed payment method) until settleAsyncPayment().
     */
    public function completePaymentCheckout(string $sessionId, bool $paid = true): void
    {
        $state = $this->store->state();
        $session = $this->store->session($state, $sessionId);

        if (($session['mode'] ?? 'subscription') !== 'payment') {
            throw new BillingException("Checkout session {$sessionId} is not a payment session.");
        }

        if ($session['status'] !== 'open') {
            throw new BillingException("Checkout session {$sessionId} is {$session['status']}.");
        }

        $paymentIntentId = $this->store->newId('pi');
        $chargeId = $this->store->newId('ch');
        $amount = (int) $session['amount_total'];

        $state['paymentIntents'][$paymentIntentId] = [
            'status' => $paid ? 'succeeded' : 'processing',
            'amount' => $amount,
            'charge_id' => $chargeId,
            'refunded' => false,
        ];
        $state['charges'][$chargeId] = [
            'invoice_id' => null,
            'amount' => $amount,
            'amount_refunded' => 0,
            'disputed' => false,
            'payment_intent_id' => $paymentIntentId,
        ];
        $state['sessions'][$sessionId]['status'] = 'complete';
        $state['sessions'][$sessionId]['payment_status'] = $paid ? 'paid' : 'unpaid';
        $state['sessions'][$sessionId]['payment_intent_id'] = $paymentIntentId;
        $state['sessions'][$sessionId]['shipping'] = ($state['sessions'][$sessionId]['collect_shipping'] ?? true)
            ? self::TEST_SHIPPING
            : null;
        $this->store->save($state);
    }

    /**
     * Whether the payment session asks for an address, as Stripe's shipping_address_collection would.
     */
    public function sessionCollectsShipping(string $sessionId): bool
    {
        return (bool) ($this->store->state()['sessions'][$sessionId]['collect_shipping'] ?? true);
    }

    /**
     * Resolve a delayed payment: it becomes paid, or the payment intent is canceled (the session stays complete and unpaid).
     */
    public function settleAsyncPayment(string $sessionId, bool $succeeded): void
    {
        $state = $this->store->state();
        $session = $this->store->session($state, $sessionId);
        $paymentIntentId = $session['payment_intent_id'] ?? null;

        if (! is_string($paymentIntentId) || $session['payment_status'] !== 'unpaid' || ! isset($state['paymentIntents'][$paymentIntentId])) {
            throw new BillingException("Checkout session {$sessionId} has no pending payment.");
        }

        $state['paymentIntents'][$paymentIntentId]['status'] = $succeeded ? 'succeeded' : 'canceled';

        if ($succeeded) {
            $state['sessions'][$sessionId]['payment_status'] = 'paid';
        }

        $this->store->save($state);
    }

    /**
     * Payment intents refunded so far.
     *
     * @return list<string>
     */
    public function refundedPaymentIntents(): array
    {
        return array_keys(array_filter($this->store->state()['paymentIntents'], fn ($paymentIntent) => $paymentIntent['refunded']));
    }

    /**
     * The idempotency key each payment intent's refund was requested with, keyed by payment intent.
     *
     * @return array<string, string>
     */
    public function refundIdempotencyKeys(): array
    {
        return $this->store->state()['refundKeys'];
    }

    /**
     * While set, upgrades fail and renewals leave the subscription past_due.
     */
    public function failPayments(bool $fail = true): void
    {
        $state = $this->store->state();
        $state['failPayments'] = $fail;
        $this->store->save($state);
    }

    /**
     * Simulate the end of a billing period: end a scheduled cancellation, fail the renewal, or renew and invoice.
     */
    public function advancePeriod(string $subscriptionId): ?InvoiceData
    {
        $state = $this->store->state();
        $subscription = $this->store->subscription($state, $subscriptionId);

        if ($subscription['status'] === 'canceled') {
            return null;
        }

        if ($subscription['cancel_at_period_end']) {
            $state['subscriptions'][$subscriptionId]['status'] = 'canceled';
            $this->store->save($state);

            return null;
        }

        if ($state['failPayments']) {
            $state['subscriptions'][$subscriptionId]['status'] = 'past_due';
            $this->store->save($state);

            return null;
        }

        $state['subscriptions'][$subscriptionId]['status'] = 'active';
        $state['subscriptions'][$subscriptionId]['period_end'] = CarbonImmutable::createFromTimestamp((int) $subscription['period_end'])->addMonth()->getTimestamp();
        $invoiceId = $this->store->addInvoice($state, $subscriptionId, (string) $subscription['customer_id'], (int) $subscription['unit_amount']);
        $this->store->save($state);

        return $this->store->invoiceData($state['invoices'][$invoiceId]);
    }

    public function setSubscriptionStatus(string $subscriptionId, string $status): void
    {
        $state = $this->store->state();
        $this->store->subscription($state, $subscriptionId);
        $state['subscriptions'][$subscriptionId]['status'] = $status;
        $this->store->save($state);
    }

    /**
     * Set the state of a connected account, registering it when unknown (e.g. the seeded demo creator's).
     */
    public function setAccount(string $accountId, bool $payoutsEnabled, bool $detailsSubmitted): void
    {
        $state = $this->store->state();
        $state['accounts'][$accountId] = ['payouts_enabled' => $payoutsEnabled, 'details_submitted' => $detailsSubmitted];
        $this->store->save($state);
    }

    /**
     * What the dev checkout page shows for a session, or null when it does not exist.
     *
     * @return array{amount: int, plan_id: int, user_id: string|null, status: string, success_url: string, cancel_url: string, mode: string, order_id: int|null}|null
     */
    public function describeSession(string $id): ?array
    {
        $session = $this->store->state()['sessions'][$id] ?? null;

        if ($session === null) {
            return null;
        }

        $isPayment = ($session['mode'] ?? 'subscription') === 'payment';

        return [
            'amount' => (int) ($isPayment ? $session['amount_total'] : $session['amount']),
            'plan_id' => $isPayment ? 0 : (int) $session['plan_id'],
            'user_id' => isset($session['metadata']['user_id']) ? (string) $session['metadata']['user_id'] : null,
            'status' => (string) $session['status'],
            'success_url' => (string) $session['success_url'],
            'cancel_url' => (string) $session['cancel_url'],
            'mode' => $isPayment ? 'payment' : 'subscription',
            'order_id' => isset($session['metadata']['order_id']) ? (int) $session['metadata']['order_id'] : null,
        ];
    }

    public function latestInvoiceId(string $subscriptionId): ?string
    {
        $invoiceId = $this->store->state()['subscriptions'][$subscriptionId]['latest_invoice_id'] ?? null;

        return $invoiceId === null ? null : (string) $invoiceId;
    }

    /**
     * The fake charge created for a paid invoice.
     */
    public function chargeIdForInvoice(string $invoiceId): string
    {
        $chargeId = $this->store->invoice($this->store->state(), $invoiceId)['charge_id'] ?? null;

        return is_string($chargeId) ? $chargeId : throw new BillingException("Invoice {$invoiceId} has no charge.");
    }

    /**
     * The fake charge created for a payment intent.
     */
    public function chargeIdForPaymentIntent(string $paymentIntentId): string
    {
        return $this->store->state()['paymentIntents'][$paymentIntentId]['charge_id'] ?? throw new BillingException("Unknown payment intent {$paymentIntentId}.");
    }

    /**
     * Simulate the cardholder disputing the charge with their bank.
     */
    public function markDisputed(string $chargeId): void
    {
        $state = $this->store->state();
        $state['charges'][$chargeId] = ['disputed' => true] + $this->store->charge($state, $chargeId);
        $this->store->save($state);
    }

    /**
     * @return list<string>
     */
    public function cancelledNow(): array
    {
        return $this->store->state()['cancelledNow'];
    }
}
