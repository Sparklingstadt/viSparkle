<?php

use App\Billing\BillingException;
use App\Billing\Data\CheckoutRequest;
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
        ->and($request['params']['payment_method_types'])->toBe(['card'])
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
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe("plan-{$plan->id}-product")
        ->and($price['path'])->toBe('/v1/prices')
        ->and($price['params'])->toMatchArray([
            'product' => 'prod_x',
            'currency' => 'jpy',
            'unit_amount' => 500,
            'recurring' => ['interval' => 'month'],
            'tax_behavior' => 'inclusive',
        ])
        ->and($this->http->header(1, 'Idempotency-Key'))->toBe("plan-{$plan->id}-price");
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
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe("user-{$user->id}-customer");
});

it('creates a connect account for the creator', function () {
    $creator = Creator::factory()->create();
    $this->http->queue(['id' => 'acct_x', 'object' => 'account']);

    expect($this->gateway->createConnectAccount($creator))->toBe('acct_x');

    $request = $this->http->requests[0];
    expect($request['path'])->toBe('/v1/accounts')
        ->and($request['params'])->toMatchArray([
            'type' => 'express',
            'country' => 'JP',
            'email' => $creator->user->email,
            'capabilities' => ['transfers' => ['requested' => true]],
            'metadata' => ['creator_id' => (string) $creator->id],
        ])
        ->and($this->http->header(0, 'Idempotency-Key'))->toBe("creator-{$creator->id}-account");
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
    $this->http->queue(['object' => 'account_link', 'url' => 'https://connect.stripe.test/onboard'])
        ->queue(['object' => 'login_link', 'url' => 'https://connect.stripe.test/dashboard'])
        ->queue(['object' => 'billing_portal.session', 'url' => 'https://billing.stripe.test/portal']);

    expect($this->gateway->createAccountOnboardingLink('acct_x', 'https://app.test/refresh', 'https://app.test/return'))->toBe('https://connect.stripe.test/onboard')
        ->and($this->gateway->createDashboardLoginLink('acct_x'))->toBe('https://connect.stripe.test/dashboard')
        ->and($this->gateway->createPortalSession('cus_x', 'https://app.test/back'))->toBe('https://billing.stripe.test/portal');

    [$link, $login, $portal] = $this->http->requests;
    expect($link['path'])->toBe('/v1/account_links')
        ->and($link['params'])->toMatchArray([
            'account' => 'acct_x',
            'refresh_url' => 'https://app.test/refresh',
            'return_url' => 'https://app.test/return',
            'type' => 'account_onboarding',
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
