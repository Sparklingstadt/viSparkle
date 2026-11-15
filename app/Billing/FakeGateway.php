<?php

namespace App\Billing;

use App\Billing\Data\AccountData;
use App\Billing\Data\ChargeData;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\InvoiceData;
use App\Billing\Data\PaymentCheckoutRequest;
use App\Billing\Data\PriceRef;
use App\Billing\Data\RefundData;
use App\Billing\Data\SubscriptionData;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;

/**
 * In-memory stand-in for Stripe, for tests (array cache) and local development (Redis). Its state lives in
 * FakeBillingStore; prices are the only thing also saved on the plan. The controls tests use to play Stripe's part are
 * in FakeGatewayControls.
 *
 * @phpstan-import-type State from FakeBillingStore
 */
class FakeGateway implements PaymentGateway
{
    use FakeGatewayControls;

    private readonly FakeBillingStore $store;

    public function __construct(Repository $cache)
    {
        $this->store = new FakeBillingStore($cache);
    }

    public function createCustomer(User $user): string
    {
        $state = $this->store->state();
        $id = $this->store->newId('cus');
        $state['customers'][$id] = ['user_id' => $user->id];
        $this->store->save($state);

        return $id;
    }

    public function ensurePrice(Plan $plan): PriceRef
    {
        $state = $this->store->state();

        if ($plan->stripe_product_id !== null && $plan->stripe_price_id !== null) {
            // Make sure this fake instance knows the price (e.g. after the cache was cleared).
            if (! isset($state['prices'][$plan->stripe_price_id])) {
                $state['prices'][$plan->stripe_price_id] = [
                    'product_id' => $plan->stripe_product_id,
                    'plan_id' => $plan->id,
                    'amount' => $plan->price,
                ];
                $this->store->save($state);
            }

            return new PriceRef($plan->stripe_product_id, $plan->stripe_price_id);
        }

        $productId = $this->store->newId('prod');
        $priceId = $this->store->newId('price');
        $state['prices'][$priceId] = ['product_id' => $productId, 'plan_id' => $plan->id, 'amount' => $plan->price];
        $this->store->save($state);

        $plan->forceFill(['stripe_product_id' => $productId, 'stripe_price_id' => $priceId])->save();

        return new PriceRef($productId, $priceId);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSessionData
    {
        $state = $this->store->state();
        $price = $this->store->price($state, $request->priceId);
        $this->store->customer($state, $request->customerId);

        $id = $this->store->newId('cs');
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
            'mode' => 'subscription',
        ];
        $this->store->save($state);

        return $this->store->sessionData($state['sessions'][$id]);
    }

    public function createPaymentCheckoutSession(PaymentCheckoutRequest $request): CheckoutSessionData
    {
        $state = $this->store->state();
        $this->store->customer($state, $request->customerId);

        $id = $this->store->newId('cs');
        $state['sessions'][$id] = [
            'id' => $id,
            'status' => 'open',
            'mode' => 'payment',
            'customer_id' => $request->customerId,
            'line_items' => array_map(
                fn ($item) => ['name' => $item->name, 'unit_amount' => $item->unitAmount, 'quantity' => $item->quantity],
                $request->lineItems,
            ),
            'amount_total' => array_sum(array_map(fn ($item) => $item->unitAmount * $item->quantity, $request->lineItems)),
            'application_fee_amount' => $request->applicationFeeAmount,
            'destination_account_id' => $request->destinationAccountId,
            'metadata' => $request->metadata,
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'expires_at' => $request->expiresAt->getTimestamp(),
            'subscription_id' => null,
            'payment_status' => 'unpaid',
            'payment_intent_id' => null,
            'shipping' => null,
            'collect_shipping' => $request->collectShipping,
        ];
        $this->store->save($state);

        return $this->store->sessionData($state['sessions'][$id]);
    }

    public function retrieveCheckoutSession(string $id): CheckoutSessionData
    {
        return $this->store->sessionData($this->store->session($this->store->state(), $id));
    }

    public function expireCheckoutSession(string $id): void
    {
        $state = $this->store->state();
        $session = $this->store->session($state, $id);

        if ($session['status'] === 'open') {
            $state['sessions'][$id]['status'] = 'expired';
            $this->store->save($state);
        }
    }

    public function retrieveSubscription(string $id): SubscriptionData
    {
        return $this->store->subscriptionData($this->store->subscription($this->store->state(), $id));
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): SubscriptionData
    {
        $state = $this->store->state();
        $this->store->subscription($state, $subscriptionId);
        $state['subscriptions'][$subscriptionId]['cancel_at_period_end'] = $cancel;
        $this->store->save($state);

        return $this->store->subscriptionData($state['subscriptions'][$subscriptionId]);
    }

    public function changeSubscriptionPrice(string $subscriptionId, string $itemId, string $priceId, bool $chargeProrationNow): SubscriptionData
    {
        $state = $this->store->state();
        $subscription = $this->store->subscription($state, $subscriptionId);
        $price = $this->store->price($state, $priceId);

        $difference = $price['amount'] - (int) $subscription['unit_amount'];

        if ($chargeProrationNow && $difference > 0) {
            if ($state['failPayments']) {
                throw new PaymentFailedException('The proration payment failed.');
            }

            $this->store->addInvoice($state, $subscriptionId, (string) $subscription['customer_id'], $difference);
        }

        $state['subscriptions'][$subscriptionId]['price_id'] = $priceId;
        $state['subscriptions'][$subscriptionId]['unit_amount'] = $price['amount'];
        $this->store->save($state);

        return $this->store->subscriptionData($state['subscriptions'][$subscriptionId]);
    }

    public function cancelSubscriptionNow(string $subscriptionId): void
    {
        $state = $this->store->state();
        $this->store->subscription($state, $subscriptionId);
        $state['subscriptions'][$subscriptionId]['status'] = 'canceled';
        $state['cancelledNow'][] = $subscriptionId;
        $this->store->save($state);
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $this->store->customer($this->store->state(), $customerId);

        return $returnUrl;
    }

    public function createConnectAccount(Creator $creator): string
    {
        $state = $this->store->state();
        $id = $this->store->newId('acct');
        $state['accounts'][$id] = ['payouts_enabled' => false, 'details_submitted' => false];
        $this->store->save($state);

        return $id;
    }

    public function createAccountOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $state = $this->store->state();
        $this->store->account($state, $accountId);
        $state['accounts'][$accountId] = ['payouts_enabled' => true, 'details_submitted' => true];
        $this->store->save($state);

        return $returnUrl;
    }

    public function createDashboardLoginLink(string $accountId): string
    {
        $this->store->account($this->store->state(), $accountId);

        return url('/creator/earnings');
    }

    public function retrieveAccount(string $accountId): AccountData
    {
        $account = $this->store->account($this->store->state(), $accountId);

        return new AccountData($accountId, $account['payouts_enabled'], $account['details_submitted']);
    }

    public function retrieveInvoice(string $id): InvoiceData
    {
        return $this->store->invoiceData($this->store->invoice($this->store->state(), $id));
    }

    public function refundInvoice(string $invoiceId): RefundData
    {
        $state = $this->store->state();
        $invoice = $this->store->invoice($state, $invoiceId);

        if ($invoice['status'] !== 'paid') {
            throw new BillingException("Invoice {$invoiceId} has no payment to refund.");
        }

        if ($invoice['refunded'] ?? false) {
            throw new BillingException("The payment of invoice {$invoiceId} has already been refunded.");
        }

        $state['invoices'][$invoiceId]['refunded'] = true;
        $chargeId = $invoice['charge_id'] ?? null;

        if (is_string($chargeId) && isset($state['charges'][$chargeId])) {
            $state['charges'][$chargeId]['amount_refunded'] = $state['charges'][$chargeId]['amount'];
        }

        $this->store->save($state);

        return new RefundData($this->store->newId('re'), (int) $invoice['amount_paid']);
    }

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): RefundData
    {
        $state = $this->store->state();
        $paymentIntent = $state['paymentIntents'][$paymentIntentId] ?? throw new BillingException("Unknown payment intent {$paymentIntentId}.");

        if ($paymentIntent['status'] !== 'succeeded') {
            throw new BillingException("Payment intent {$paymentIntentId} has no payment to refund.");
        }

        if ($paymentIntent['refunded']) {
            throw new BillingException("Payment intent {$paymentIntentId} has already been refunded.");
        }

        $state['paymentIntents'][$paymentIntentId]['refunded'] = true;
        $state['refundKeys'][$paymentIntentId] = $idempotencyKey;
        $state['charges'][$paymentIntent['charge_id']] = ['amount_refunded' => $paymentIntent['amount']] + $this->store->charge($state, $paymentIntent['charge_id']);
        $this->store->save($state);

        return new RefundData($this->store->newId('re'), $paymentIntent['amount']);
    }

    public function retrieveCharge(string $chargeId): ChargeData
    {
        $charge = $this->store->charge($this->store->state(), $chargeId);

        return new ChargeData($chargeId, $charge['invoice_id'], $charge['amount_refunded'], $charge['disputed'], $charge['payment_intent_id'] ?? null);
    }

    public function retrievePaymentIntentStatus(string $paymentIntentId): string
    {
        $paymentIntent = $this->store->state()['paymentIntents'][$paymentIntentId] ?? throw new BillingException("Unknown payment intent {$paymentIntentId}.");

        return $paymentIntent['status'];
    }

    public function retrieveLatestChargeId(string $paymentIntentId): ?string
    {
        $paymentIntent = $this->store->state()['paymentIntents'][$paymentIntentId] ?? throw new BillingException("Unknown payment intent {$paymentIntentId}.");

        return $paymentIntent['charge_id'];
    }
}
