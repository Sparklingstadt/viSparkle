<?php

use App\Billing\BillingException;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\LineItem;
use App\Billing\Data\PaymentCheckoutRequest;
use App\Billing\Data\ShippingDetails;
use App\Billing\PaymentFailedException;
use App\Billing\PaymentGateway;
use App\Billing\StripeGateway;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Stripe\ApiRequestor;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription;
use Tests\Support\RecordingStripeHttpClient;

beforeEach(function () {
    $this->http = new RecordingStripeHttpClient;
    ApiRequestor::setHttpClient($this->http);
    config(['fanbox.billing.idempotency_prefix' => 'test.app']);
    $this->gateway = new StripeGateway(new StripeClient('sk_test_x'));
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function stripeSubscriptionBody(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'sub_x',
        'object' => 'subscription',
        'customer' => 'cus_x',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'metadata' => ['plan_id' => '1'],
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_x',
            'object' => 'subscription_item',
            'current_period_end' => 1793836800,
            'price' => ['id' => 'price_x', 'object' => 'price', 'unit_amount' => 500],
        ]]],
    ], $overrides);
}

it('creates a destination-charge checkout session', function () {
    $this->http->queue([
        'id' => 'cs_x', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_x',
        'status' => 'open', 'subscription' => null, 'customer' => 'cus_x', 'metadata' => ['plan_id' => '7'],
    ]);

    $session = $this->gateway->createCheckoutSession(new CheckoutRequest(
        customerId: 'cus_x',
        priceId: 'price_x',
        destinationAccountId: 'acct_x',
        feePercent: 10,
        metadata: ['plan_id' => '7'],
        successUrl: 'https://app.test/success',
        cancelUrl: 'https://app.test/cancel',
        expiresAt: CarbonImmutable::createFromTimestamp(1793836800),
    ));

    $request = $this->http->requests[0];
    expect($request['method'])->toBe('post')
        ->and($request['path'])->toBe('/v1/checkout/sessions')
        ->and($request['params']['mode'])->toBe('subscription')
        ->and($request['params']['customer'])->toBe('cus_x')
        ->and($request['params']['locale'])->toBe('ja')
        ->and($request['params'])->not->toHaveKey('payment_method_types')
        ->and($request['params']['expires_at'])->toBe(1793836800)
        ->and($request['params']['line_items'][0]['price'])->toBe('price_x')
        ->and($request['params']['line_items'][0]['quantity'])->toBe(1)
        ->and($request['params']['subscription_data']['application_fee_percent'])->toBe(10)
        ->and($request['params']['subscription_data']['transfer_data']['destination'])->toBe('acct_x')
        ->and($request['params']['subscription_data']['metadata'])->toBe(['plan_id' => '7'])
        ->and($request['params']['metadata'])->toBe(['plan_id' => '7'])
        ->and($request['params']['success_url'])->toBe('https://app.test/success')
        ->and($request['params']['cancel_url'])->toBe('https://app.test/cancel');
    expect($session->id)->toBe('cs_x')
        ->and($session->url)->toBe('https://checkout.stripe.test/cs_x')
        ->and($session->status)->toBe('open')
        ->and($session->subscriptionId)->toBeNull()
        ->and($session->customerId)->toBe('cus_x')
        ->and($session->metadata)->toBe(['plan_id' => '7']);
});

it('maps a completed checkout session with a subscription id', function () {
    $this->http->queue([
        'id' => 'cs_x', 'object' => 'checkout.session', 'url' => null,
        'status' => 'complete', 'subscription' => 'sub_x', 'customer' => 'cus_x', 'metadata' => [],
    ]);

    $session = $this->gateway->retrieveCheckoutSession('cs_x');

    expect($this->http->requests[0]['method'])->toBe('get')
        ->and($session->status)->toBe('complete')
        ->and($session->subscriptionId)->toBe('sub_x')
        ->and($session->url)->toBe('');
});

it('treats a pending update as a failed upgrade', function () {
    $this->http->queue(stripeSubscriptionBody([
        'pending_update' => ['expires_at' => 1793836800, 'subscription_items' => []],
    ]));

    expect(fn () => $this->gateway->changeSubscriptionPrice('sub_x', 'si_x', 'price_y', true))
        ->toThrow(PaymentFailedException::class);

    $params = $this->http->requests[0]['params'];
    expect($this->http->requests[0]['path'])->toBe('/v1/subscriptions/sub_x')
        ->and($params['items'])->toBe([['id' => 'si_x', 'price' => 'price_y']])
        ->and($params['proration_behavior'])->toBe('always_invoice')
        ->and($params['payment_behavior'])->toBe('pending_if_incomplete');
});

it('changes the price without proration for a downgrade', function () {
    $this->http->queue(stripeSubscriptionBody(['items' => ['data' => [['price' => ['id' => 'price_y', 'unit_amount' => 300]]]]]));

    $subscription = $this->gateway->changeSubscriptionPrice('sub_x', 'si_x', 'price_y', false);

    $params = $this->http->requests[0]['params'];
    expect($params['proration_behavior'])->toBe('none')
        ->and($params['payment_behavior'])->toBe('allow_incomplete')
        ->and($subscription->priceId)->toBe('price_y')
        ->and($subscription->unitAmount)->toBe(300);
});

it('reads the period end from the subscription item', function () {
    $subscription = StripeGateway::subscriptionFrom(Subscription::constructFrom(stripeSubscriptionBody()));

    expect($subscription->currentPeriodEnd?->timestamp)->toBe(1793836800)
        ->and($subscription->currentPeriodEnd->timezone->getName())->toBe(config('app.timezone'))
        ->and($subscription->id)->toBe('sub_x')
        ->and($subscription->customerId)->toBe('cus_x')
        ->and($subscription->status)->toBe('active')
        ->and($subscription->itemId)->toBe('si_x')
        ->and($subscription->priceId)->toBe('price_x')
        ->and($subscription->unitAmount)->toBe(500)
        ->and($subscription->cancelAtPeriodEnd)->toBeFalse()
        ->and($subscription->metadata)->toBe(['plan_id' => '1']);
});

it('falls back to the subscription period end and tolerates an expanded customer', function () {
    $body = stripeSubscriptionBody(['customer' => ['id' => 'cus_expanded', 'object' => 'customer'], 'current_period_end' => 1790000000]);
    unset($body['items']['data'][0]['current_period_end']);

    $subscription = StripeGateway::subscriptionFrom(Subscription::constructFrom($body));

    expect($subscription->currentPeriodEnd?->timestamp)->toBe(1790000000)
        ->and($subscription->customerId)->toBe('cus_expanded');
});

it('maps a subscription without items to null item fields', function () {
    $subscription = StripeGateway::subscriptionFrom(Subscription::constructFrom([
        'id' => 'sub_x', 'customer' => 'cus_x', 'status' => 'incomplete', 'cancel_at_period_end' => false,
        'metadata' => [], 'items' => ['object' => 'list', 'data' => []],
    ]));

    expect($subscription->itemId)->toBeNull()
        ->and($subscription->priceId)->toBeNull()
        ->and($subscription->unitAmount)->toBeNull()
        ->and($subscription->currentPeriodEnd)->toBeNull();
});

it('reads the invoice subscription from the parent details', function () {
    $invoice = StripeGateway::invoiceFrom(Invoice::constructFrom([
        'id' => 'in_x', 'object' => 'invoice', 'customer' => 'cus_x', 'status' => 'paid', 'amount_paid' => 500,
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_x']],
        'status_transitions' => ['paid_at' => 1793836800],
    ]));

    expect($invoice->id)->toBe('in_x')
        ->and($invoice->subscriptionId)->toBe('sub_x')
        ->and($invoice->customerId)->toBe('cus_x')
        ->and($invoice->status)->toBe('paid')
        ->and($invoice->amountPaid)->toBe(500)
        ->and($invoice->paidAt?->timestamp)->toBe(1793836800)
        ->and($invoice->paidAt->timezone->getName())->toBe(config('app.timezone'));
});

it('falls back to the legacy invoice subscription field', function () {
    $invoice = StripeGateway::invoiceFrom(Invoice::constructFrom([
        'id' => 'in_x', 'customer' => 'cus_x', 'status' => 'open', 'amount_paid' => 0,
        'subscription' => 'sub_legacy', 'status_transitions' => ['paid_at' => null],
    ]));

    expect($invoice->subscriptionId)->toBe('sub_legacy')
        ->and($invoice->paidAt)->toBeNull();
});

it('wraps API errors', function (int $status) {
    Log::spy();
    $this->http->queue(['error' => ['type' => $status === 402 ? 'card_error' : 'api_error', 'message' => 'Boom']], $status);

    expect(fn () => $this->gateway->retrieveSubscription('sub_x'))
        ->toThrow(BillingException::class, 'Boom');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['request_id'] === 'req_test')->once();
})->with([402, 500]);

it('reports a declined card on an upgrade as a failed payment', function () {
    $this->http->queue(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']], 402);

    expect(fn () => $this->gateway->changeSubscriptionPrice('sub_x', 'si_x', 'price_y', true))
        ->toThrow(PaymentFailedException::class, 'Your card was declined.');
});

it('keeps other API errors on a plan change as plain billing errors', function () {
    $this->http->queue(['error' => ['type' => 'api_error', 'message' => 'Boom']], 500);

    try {
        $this->gateway->changeSubscriptionPrice('sub_x', 'si_x', 'price_y', true);
        $this->fail('Expected a BillingException.');
    } catch (BillingException $e) {
        expect($e)->not->toBeInstanceOf(PaymentFailedException::class);
    }
});

it('refuses to build the stripe driver without a secret', function (?string $secret) {
    config(['fanbox.billing.driver' => 'stripe', 'fanbox.billing.stripe.secret' => $secret]);

    expect(fn () => app(PaymentGateway::class))
        ->toThrow(BillingException::class, 'Stripe secret is not configured.');
})->with([[null], ['']]);

it('builds the stripe driver when a secret is configured', function () {
    config(['fanbox.billing.driver' => 'stripe', 'fanbox.billing.stripe.secret' => 'sk_test_x']);

    expect(app(PaymentGateway::class))->toBeInstanceOf(StripeGateway::class);
});

it('creates the plan price once', function () {
    $creator = Creator::factory()->create(['display_name' => 'Alice']);
    $plan = Plan::factory()->for($creator)->price(500)->create(['name' => 'Basic']);

    $this->http->queue(['id' => 'prod_x', 'object' => 'product'])
        ->queue(['id' => 'price_x', 'object' => 'price']);

    $ref = $this->gateway->ensurePrice($plan);
    $again = $this->gateway->ensurePrice($plan->fresh());

    expect($this->http->requests)->toHaveCount(2)
        ->and($ref->productId)->toBe('prod_x')
        ->and($ref->priceId)->toBe('price_x')
        ->and($again->priceId)->toBe('price_x')
        ->and($plan->fresh()->stripe_product_id)->toBe('prod_x')
        ->and($plan->fresh()->stripe_price_id)->toBe('price_x');

    [$product, $price] = $this->http->requests;
    expect($product['path'])->toBe('/v1/products')
        ->and($product['params']['name'])->toBe('Alice Basic')
        ->and($product['params']['metadata'])->toBe(['plan_id' => (string) $plan->id])
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe("test.app:plan-{$plan->id}-product")
        ->and($price['path'])->toBe('/v1/prices')
        ->and($price['params'])->toMatchArray([
            'product' => 'prod_x',
            'currency' => 'jpy',
            'unit_amount' => 500,
            'recurring' => ['interval' => 'month'],
            'tax_behavior' => 'inclusive',
        ])
        ->and($this->http->header(1, 'Idempotency-Key'))->toBe("test.app:plan-{$plan->id}-price");
});

it('creates a customer with an idempotency key', function () {
    $user = User::factory()->create();
    $this->http->queue(['id' => 'cus_x', 'object' => 'customer']);

    expect($this->gateway->createCustomer($user))->toBe('cus_x');

    $request = $this->http->requests[0];
    expect($request['path'])->toBe('/v1/customers')
        ->and($request['params']['email'])->toBe($user->email)
        ->and($request['params']['name'])->toBe($user->name)
        ->and($request['params']['metadata'])->toBe(['user_id' => (string) $user->id])
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe("test.app:user-{$user->id}-customer");
});

it('creates a connect account for the creator', function () {
    $creator = Creator::factory()->create();
    $this->http->queue(['id' => 'acct_x', 'object' => 'v2.core.account']);

    expect($this->gateway->createConnectAccount($creator))->toBe('acct_x');

    $request = $this->http->requests[0];
    expect($request['path'])->toBe('/v2/core/accounts')
        ->and($request['params'])->toMatchArray([
            'contact_email' => $creator->user->email,
            'display_name' => $creator->display_name,
            'identity' => ['country' => 'jp'],
            'dashboard' => 'express',
            'defaults' => ['responsibilities' => ['fees_collector' => 'application', 'losses_collector' => 'application']],
            'configuration' => ['recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
            'metadata' => ['creator_id' => (string) $creator->id],
        ])
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe("test.app:creator-{$creator->id}-account-v2");
});

it('logs stripe notices instead of failing the call', function () {
    Log::spy();
    $creator = Creator::factory()->create();
    $this->http->queue(['id' => 'acct_x', 'object' => 'account'], headers: ['Stripe-Notice' => 'We recommend building your integration using Accounts v2.']);

    expect($this->gateway->createConnectAccount($creator))->toBe('acct_x');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'billing.stripe_notice' && str_contains($context['message'], 'Accounts v2'))
        ->once();
});

it('returns onboarding, dashboard and portal URLs', function () {
    $this->http->queue(['object' => 'v2.core.account_link', 'url' => 'https://connect.stripe.test/onboard'])
        ->queue(['object' => 'login_link', 'url' => 'https://connect.stripe.test/dashboard'])
        ->queue(['object' => 'billing_portal.session', 'url' => 'https://billing.stripe.test/portal']);

    expect($this->gateway->createAccountOnboardingLink('acct_x', 'https://app.test/refresh', 'https://app.test/return'))->toBe('https://connect.stripe.test/onboard')
        ->and($this->gateway->createDashboardLoginLink('acct_x'))->toBe('https://connect.stripe.test/dashboard')
        ->and($this->gateway->createPortalSession('cus_x', 'https://app.test/back'))->toBe('https://billing.stripe.test/portal');

    [$link, $login, $portal] = $this->http->requests;
    expect($link['path'])->toBe('/v2/core/account_links')
        ->and($link['params'])->toMatchArray([
            'account' => 'acct_x',
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => ['refresh_url' => 'https://app.test/refresh', 'return_url' => 'https://app.test/return'],
            ],
        ])
        ->and($login['path'])->toBe('/v1/accounts/acct_x/login_links')
        ->and($portal['path'])->toBe('/v1/billing_portal/sessions')
        ->and($portal['params'])->toMatchArray(['customer' => 'cus_x', 'return_url' => 'https://app.test/back']);
});

it('only enables payouts when transfers is active', function (bool $payouts, string $transfers, bool $expected) {
    $this->http->queue([
        'id' => 'acct_x', 'object' => 'account', 'payouts_enabled' => $payouts,
        'details_submitted' => true, 'capabilities' => ['transfers' => $transfers],
    ]);

    $account = $this->gateway->retrieveAccount('acct_x');

    expect($account->id)->toBe('acct_x')
        ->and($account->payoutsEnabled)->toBe($expected)
        ->and($account->detailsSubmitted)->toBeTrue();
})->with([
    'both ready' => [true, 'active', true],
    'transfers pending' => [true, 'pending', false],
    'payouts disabled' => [false, 'active', false],
]);

it('cancels, schedules cancellation and expires through the matching endpoints', function () {
    $this->http->queue(stripeSubscriptionBody(['status' => 'canceled']))
        ->queue(stripeSubscriptionBody(['cancel_at_period_end' => true]))
        ->queue(['id' => 'cs_x', 'object' => 'checkout.session', 'status' => 'expired']);

    $this->gateway->cancelSubscriptionNow('sub_x');
    $scheduled = $this->gateway->setCancelAtPeriodEnd('sub_x', true);
    $this->gateway->expireCheckoutSession('cs_x');

    [$cancel, $schedule, $expire] = $this->http->requests;
    expect($cancel['method'])->toBe('delete')
        ->and($cancel['path'])->toBe('/v1/subscriptions/sub_x')
        ->and($schedule['path'])->toBe('/v1/subscriptions/sub_x')
        ->and($schedule['params'])->toBe(['cancel_at_period_end' => 'true'])
        ->and($scheduled->cancelAtPeriodEnd)->toBeTrue()
        ->and($expire['path'])->toBe('/v1/checkout/sessions/cs_x/expire');
});

it('retrieves an invoice', function () {
    $this->http->queue([
        'id' => 'in_x', 'object' => 'invoice', 'customer' => 'cus_x', 'status' => 'paid', 'amount_paid' => 500,
        'parent' => ['subscription_details' => ['subscription' => 'sub_x']],
        'status_transitions' => ['paid_at' => 1793836800],
    ]);

    $invoice = $this->gateway->retrieveInvoice('in_x');

    expect($this->http->requests[0]['path'])->toBe('/v1/invoices/in_x')
        ->and($invoice->subscriptionId)->toBe('sub_x');
});

function stripeInvoicePaymentsBody(array $payments): array
{
    return ['object' => 'list', 'url' => '/v1/invoice_payments', 'has_more' => false, 'data' => $payments];
}

function stripeInvoicePayment(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'inpay_x',
        'object' => 'invoice_payment',
        'amount_paid' => 500,
        'invoice' => 'in_x',
        'is_default' => true,
        'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_x'],
        'status' => 'paid',
    ], $overrides);
}

it('refunds the invoice payment in full, reversing the transfer and the application fee', function () {
    $this->http->queue(stripeInvoicePaymentsBody([stripeInvoicePayment()]))
        ->queue(['id' => 're_x', 'object' => 'refund', 'amount' => 500, 'charge' => 'ch_x', 'payment_intent' => 'pi_x', 'status' => 'succeeded']);

    $refund = $this->gateway->refundInvoice('in_x');

    [$lookup, $create] = $this->http->requests;
    expect($lookup['method'])->toBe('get')
        ->and($lookup['path'])->toBe('/v1/invoice_payments')
        ->and($lookup['params'])->toBe(['invoice' => 'in_x'])
        ->and($create['method'])->toBe('post')
        ->and($create['path'])->toBe('/v1/refunds')
        ->and($create['params'])->toMatchArray([
            'payment_intent' => 'pi_x',
            'reverse_transfer' => 'true',
            'refund_application_fee' => 'true',
            'metadata' => ['invoice' => 'in_x'],
        ])
        ->and($create['params'])->not->toHaveKey('amount')
        ->and($this->http->header(1, 'Idempotency-Key'))->toBe('test.app:refund-invoice-in_x')
        ->and($refund->id)->toBe('re_x')
        ->and($refund->amount)->toBe(500);
});

it('refunds an invoice paid by a bare charge', function () {
    $this->http->queue(stripeInvoicePaymentsBody([stripeInvoicePayment(['payment' => ['type' => 'charge', 'charge' => 'ch_x', 'payment_intent' => null]])]))
        ->queue(['id' => 're_x', 'object' => 'refund', 'amount' => 500]);

    $this->gateway->refundInvoice('in_x');

    expect($this->http->requests[1]['params'])->toMatchArray(['charge' => 'ch_x', 'reverse_transfer' => 'true'])
        ->and($this->http->requests[1]['params'])->not->toHaveKey('payment_intent');
});

it('falls back to the legacy invoice payment intent field', function () {
    $this->http->queue(stripeInvoicePaymentsBody([]))
        ->queue(['id' => 'in_x', 'object' => 'invoice', 'status' => 'paid', 'payment_intent' => 'pi_legacy'])
        ->queue(['id' => 're_x', 'object' => 'refund', 'amount' => 500]);

    $this->gateway->refundInvoice('in_x');

    expect($this->http->requests[1]['path'])->toBe('/v1/invoices/in_x')
        ->and($this->http->requests[2]['params']['payment_intent'])->toBe('pi_legacy');
});

it('refuses to refund an invoice without a payment', function () {
    $this->http->queue(stripeInvoicePaymentsBody([]))
        ->queue(['id' => 'in_x', 'object' => 'invoice', 'status' => 'open']);

    expect(fn () => $this->gateway->refundInvoice('in_x'))->toThrow(BillingException::class);
    expect($this->http->requests)->toHaveCount(2);
});

it('wraps an already refunded charge as a billing error', function () {
    $this->http->queue(stripeInvoicePaymentsBody([stripeInvoicePayment()]))
        ->queue(['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge ch_x has already been refunded.']], 400);

    expect(fn () => $this->gateway->refundInvoice('in_x'))->toThrow(BillingException::class, 'already been refunded');
});

it('retrieves a charge and finds its invoice through the payment intent', function () {
    $this->http->queue([
        'id' => 'ch_x', 'object' => 'charge', 'amount' => 500, 'amount_refunded' => 200,
        'disputed' => true, 'payment_intent' => 'pi_x', 'refunded' => false,
    ])->queue(stripeInvoicePaymentsBody([stripeInvoicePayment(['invoice' => 'in_x'])]));

    $charge = $this->gateway->retrieveCharge('ch_x');

    [$get, $lookup] = $this->http->requests;
    expect($get['path'])->toBe('/v1/charges/ch_x')
        ->and($lookup['path'])->toBe('/v1/invoice_payments')
        ->and($lookup['params'])->toMatchArray(['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_x']])
        ->and($charge->id)->toBe('ch_x')
        ->and($charge->invoiceId)->toBe('in_x')
        ->and($charge->amountRefunded)->toBe(200)
        ->and($charge->disputed)->toBeTrue();
});

it('reads the legacy charge invoice field without a lookup', function () {
    $this->http->queue([
        'id' => 'ch_x', 'object' => 'charge', 'amount_refunded' => 0, 'disputed' => false,
        'payment_intent' => 'pi_x', 'invoice' => 'in_legacy',
    ]);

    $charge = $this->gateway->retrieveCharge('ch_x');

    expect($this->http->requests)->toHaveCount(1)
        ->and($charge->invoiceId)->toBe('in_legacy')
        ->and($charge->disputed)->toBeFalse();
});

it('maps a charge that is not for an invoice', function (?string $paymentIntent) {
    $this->http->queue(['id' => 'ch_x', 'object' => 'charge', 'amount_refunded' => 0, 'payment_intent' => $paymentIntent]);
    if ($paymentIntent !== null) {
        $this->http->queue(stripeInvoicePaymentsBody([]));
    }

    expect($this->gateway->retrieveCharge('ch_x')->invoiceId)->toBeNull();
})->with(['no payment intent' => [null], 'payment intent without invoice' => ['pi_x']]);

it('skips invoice payments that are not paid', function () {
    $this->http->queue(stripeInvoicePaymentsBody([
        stripeInvoicePayment(['id' => 'inpay_failed', 'status' => 'canceled', 'payment' => ['payment_intent' => 'pi_failed']]),
        stripeInvoicePayment(['payment' => ['payment_intent' => 'pi_paid']]),
    ]))->queue(['id' => 're_x', 'object' => 'refund', 'amount' => 500]);

    $this->gateway->refundInvoice('in_x');

    expect($this->http->requests[1]['params']['payment_intent'])->toBe('pi_paid');
});

it('maps the latest invoice of a subscription', function (mixed $latest) {
    $subscription = StripeGateway::subscriptionFrom(Subscription::constructFrom(stripeSubscriptionBody(['latest_invoice' => $latest])));

    expect($subscription->latestInvoiceId)->toBe('in_x');
})->with(['id' => ['in_x'], 'expanded' => [['id' => 'in_x', 'object' => 'invoice']]]);

it('creates a payment checkout with destination, fee and shipping collection', function () {
    $this->http->queue([
        'id' => 'cs_p', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_p',
        'status' => 'open', 'customer' => 'cus_x', 'metadata' => ['order_id' => '7'], 'payment_status' => 'unpaid',
    ]);

    $session = $this->gateway->createPaymentCheckoutSession(new PaymentCheckoutRequest(
        customerId: 'cus_x',
        lineItems: [new LineItem('マグカップ', 1500, 2)],
        destinationAccountId: 'acct_x',
        applicationFeeAmount: 300,
        metadata: ['order_id' => '7'],
        successUrl: 'https://app.test/success',
        cancelUrl: 'https://app.test/cancel',
        expiresAt: CarbonImmutable::createFromTimestamp(1793836800),
        idempotencyKey: 'order-7-checkout',
    ));

    $request = $this->http->requests[0];
    expect($request['method'])->toBe('post')
        ->and($request['path'])->toBe('/v1/checkout/sessions')
        ->and($request['params']['mode'])->toBe('payment')
        ->and($request['params']['customer'])->toBe('cus_x')
        ->and($request['params']['locale'])->toBe('ja')
        ->and($request['params']['line_items'][0]['price_data']['currency'])->toBe('jpy')
        ->and($request['params']['line_items'][0]['price_data']['unit_amount'])->toBe(1500)
        ->and($request['params']['line_items'][0]['price_data']['product_data']['name'])->toBe('マグカップ')
        ->and($request['params']['line_items'][0]['quantity'])->toBe(2)
        ->and($request['params']['shipping_address_collection']['allowed_countries'])->toBe(['JP'])
        ->and($request['params']['phone_number_collection']['enabled'])->toBe('true')
        ->and($request['params']['expires_at'])->toBe(1793836800)
        ->and($request['params']['success_url'])->toBe('https://app.test/success')
        ->and($request['params']['cancel_url'])->toBe('https://app.test/cancel')
        ->and($request['params']['metadata'])->toBe(['order_id' => '7'])
        ->and($request['params']['payment_intent_data']['application_fee_amount'])->toBe(300)
        ->and($request['params']['payment_intent_data']['transfer_data']['destination'])->toBe('acct_x')
        ->and($request['params']['payment_intent_data']['metadata'])->toBe(['order_id' => '7'])
        ->and($this->http->header(0, 'Idempotency-Key'))->toContain('order-7-checkout')
        ->and($session->id)->toBe('cs_p')
        ->and($session->paymentStatus)->toBe('unpaid');
});

it('omits shipping collection for a digital-only checkout', function () {
    $this->http->queue([
        'id' => 'cs_d', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_d',
        'status' => 'open', 'customer' => 'cus_x', 'metadata' => ['order_id' => '8'], 'payment_status' => 'unpaid',
    ]);

    $this->gateway->createPaymentCheckoutSession(new PaymentCheckoutRequest(
        customerId: 'cus_x',
        lineItems: [new LineItem('イラスト集', 800, 1)],
        destinationAccountId: 'acct_x',
        applicationFeeAmount: 80,
        metadata: ['order_id' => '8'],
        successUrl: 'https://app.test/success',
        cancelUrl: 'https://app.test/cancel',
        expiresAt: CarbonImmutable::createFromTimestamp(1793836800),
        idempotencyKey: 'order-8-checkout',
        collectShipping: false,
    ));

    expect($this->http->requests[0]['params'])->not->toHaveKey('shipping_address_collection')
        ->not->toHaveKey('phone_number_collection');
});

it('reads shipping details and payment status from a session', function () {
    $this->http->queue([
        'id' => 'cs_p', 'object' => 'checkout.session', 'url' => null, 'status' => 'complete',
        'customer' => 'cus_x', 'metadata' => ['order_id' => '7'], 'payment_status' => 'paid',
        'payment_intent' => 'pi_x',
        'customer_details' => ['phone' => '+819012345678'],
        'collected_information' => ['shipping_details' => [
            'name' => '山田 花子',
            'address' => ['country' => 'JP', 'postal_code' => '150-0001', 'state' => '東京都', 'city' => '渋谷区', 'line1' => '神宮前1-2-3', 'line2' => 'ハイツ101'],
        ]],
    ]);

    $session = $this->gateway->retrieveCheckoutSession('cs_p');

    expect($session->paymentStatus)->toBe('paid')
        ->and($session->paymentIntentId)->toBe('pi_x')
        ->and($session->shipping)->toEqual(new ShippingDetails('山田 花子', '150-0001', '東京都 渋谷区 神宮前1-2-3 ハイツ101', '+819012345678'));
});

it('reads an expanded payment intent and leaves shipping empty when none was collected', function () {
    $this->http->queue([
        'id' => 'cs_p', 'object' => 'checkout.session', 'status' => 'open', 'metadata' => [],
        'payment_intent' => ['id' => 'pi_y', 'object' => 'payment_intent'],
    ]);

    $session = $this->gateway->retrieveCheckoutSession('cs_p');

    expect($session->paymentIntentId)->toBe('pi_y')
        ->and($session->shipping)->toBeNull();
});

it('refunds a payment intent with transfer reversal and fee refund', function () {
    $this->http->queue(['id' => 're_p', 'object' => 'refund', 'amount' => 3000, 'status' => 'succeeded']);

    $refund = $this->gateway->refundPaymentIntent('pi_x', 'order-7-refund');

    $request = $this->http->requests[0];
    expect($request['method'])->toBe('post')
        ->and($request['path'])->toBe('/v1/refunds')
        ->and($request['params'])->toMatchArray([
            'payment_intent' => 'pi_x',
            'reverse_transfer' => 'true',
            'refund_application_fee' => 'true',
        ])
        ->and($request['params'])->not->toHaveKey('amount')
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe('test.app:order-7-refund')
        ->and($refund->id)->toBe('re_p')
        ->and($refund->amount)->toBe(3000);
});

it('reads the payment intent of a charge', function () {
    $this->http->queue(['id' => 'ch_x', 'object' => 'charge', 'amount_refunded' => 0, 'disputed' => false, 'payment_intent' => 'pi_x', 'invoice' => 'in_x']);

    expect($this->gateway->retrieveCharge('ch_x')->paymentIntentId)->toBe('pi_x');
});

it('reads the status of a payment intent', function () {
    $this->http->queue(['id' => 'pi_x', 'object' => 'payment_intent', 'status' => 'requires_payment_method', 'latest_charge' => null]);

    expect($this->gateway->retrievePaymentIntentStatus('pi_x'))->toBe('requires_payment_method');

    $request = $this->http->requests[0];
    expect($request['method'])->toBe('get')
        ->and($request['path'])->toBe('/v1/payment_intents/pi_x');
});

it('reads the latest charge of a payment intent', function (mixed $latest, ?string $expected) {
    $this->http->queue(['id' => 'pi_x', 'object' => 'payment_intent', 'status' => 'succeeded', 'latest_charge' => $latest]);

    expect($this->gateway->retrieveLatestChargeId('pi_x'))->toBe($expected)
        ->and($this->http->requests[0]['path'])->toBe('/v1/payment_intents/pi_x');
})->with([
    'id' => ['ch_x', 'ch_x'],
    'expanded' => [['id' => 'ch_y', 'object' => 'charge'], 'ch_y'],
    'none' => [null, null],
]);
