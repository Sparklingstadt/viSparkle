<?php

use App\Billing\FakeGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Enums\SupportStatus;
use App\Jobs\ProcessStripeEvent;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\StripeEvent;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use App\Services\Shop\Orders;
use App\Services\Shop\OrderSync;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'fanbox.billing.stripe.webhook_secret' => 'whsec_platform',
        'fanbox.billing.stripe.connect_webhook_secret' => 'whsec_connect',
    ]);
});

/**
 * POST a signed event to the platform (or connect) webhook endpoint.
 *
 * @param  array<string, mixed>  $object
 * @param  array<string, mixed>  $extra  top-level event fields (e.g. `account`)
 */
function postStripeEvent(string $id, string $type, array $object, bool $connect = false, ?string $secret = null, array $extra = []): TestResponse
{
    $payload = json_encode(['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]] + $extra, JSON_THROW_ON_ERROR);
    $secret ??= $connect ? 'whsec_connect' : 'whsec_platform';

    return test()->call('POST', $connect ? '/stripe/connect-webhook' : '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

it('rejects an invalid signature', function () {
    $payload = json_encode(['id' => 'evt_bad', 'object' => 'event', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_1']]], JSON_THROW_ON_ERROR);

    test()->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_wrong'),
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(400);

    test()->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);

    expect(StripeEvent::count())->toBe(0);
});

it('fails with a server error when the secret is not configured', function () {
    config(['fanbox.billing.stripe.webhook_secret' => null]);

    postStripeEvent('evt_1', 'invoice.paid', ['id' => 'in_1'])->assertStatus(500);

    expect(StripeEvent::count())->toBe(0);
});

it('syncs a subscription on customer.subscription.updated', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $fan = User::factory()->create();
    $subscription = fakeCheckout($gateway, $plan, $fan);
    app(SubscriptionSync::class)->sync($subscription->id);
    expect(Support::sole()->status)->toBe(SupportStatus::Active);

    $gateway->setSubscriptionStatus($subscription->id, 'canceled');
    postStripeEvent('evt_upd', 'customer.subscription.updated', ['id' => $subscription->id])->assertOk();

    expect(Support::sole()->status)->toBe(SupportStatus::Ended)
        ->and(StripeEvent::findOrFail('evt_upd')->processed_at)->not->toBeNull();
});

it('creates the support from checkout.session.completed', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $fan = User::factory()->create();
    $subscription = fakeCheckout($gateway, $plan, $fan);
    expect(Support::count())->toBe(0);

    postStripeEvent('evt_co', 'checkout.session.completed', ['id' => 'cs_1', 'subscription' => $subscription->id])->assertOk();

    $support = Support::sole();
    expect($support->user_id)->toBe($fan->id)
        ->and($support->status)->toBe(SupportStatus::Active)
        ->and($support->stripe_subscription_id)->toBe($subscription->id);
});

it('records a payment on invoice.paid', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->price(800)->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);

    postStripeEvent('evt_paid', 'invoice.paid', ['id' => $invoiceId])->assertOk();

    $payment = Payment::sole();
    expect($payment->stripe_invoice_id)->toBe($invoiceId)
        ->and($payment->amount)->toBe(800)
        ->and(Support::count())->toBe(1);
});

it('syncs the subscription on invoice.payment_failed', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);
    app(SubscriptionSync::class)->sync($subscription->id);

    $gateway->setSubscriptionStatus($subscription->id, 'past_due');
    postStripeEvent('evt_fail', 'invoice.payment_failed', ['id' => $invoiceId])->assertOk();

    expect(Support::sole()->stripe_status)->toBe('past_due');
});

it('ignores a replayed event id', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);

    postStripeEvent('evt_dup', 'invoice.paid', ['id' => $invoiceId])->assertOk();
    postStripeEvent('evt_dup', 'invoice.paid', ['id' => $invoiceId])->assertOk();

    expect(Payment::count())->toBe(1)
        ->and(StripeEvent::count())->toBe(1);
});

it('dispatches nothing for a replayed event that was already processed', function () {
    Queue::fake();

    postStripeEvent('evt_once', 'customer.subscription.updated', ['id' => 'sub_1'])->assertOk();
    StripeEvent::findOrFail('evt_once')->forceFill(['processed_at' => now()])->save(); // the faked queue never runs the job
    postStripeEvent('evt_once', 'customer.subscription.updated', ['id' => 'sub_1'])->assertOk();

    Queue::assertPushed(ProcessStripeEvent::class, 1);
    Queue::assertPushed(ProcessStripeEvent::class, fn (ProcessStripeEvent $job) => $job->eventId === 'evt_once'
        && $job->type === 'customer.subscription.updated'
        && $job->objectId === 'sub_1');
});

it('processes a redelivered event whose earlier delivery never ran', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    // A previous delivery stored the id but could not enqueue the job.
    StripeEvent::query()->create(['id' => 'evt_retry', 'type' => 'checkout.session.completed']);

    postStripeEvent('evt_retry', 'checkout.session.completed', ['id' => 'cs_1', 'subscription' => $subscription->id])->assertOk();

    expect(Support::sole()->stripe_subscription_id)->toBe($subscription->id)
        ->and(StripeEvent::findOrFail('evt_retry')->processed_at)->not->toBeNull();
});

it('enables a creator on account.updated via the connect endpoint', function () {
    $gateway = fakeBilling();
    $creator = Creator::factory()->create();
    $accountId = $gateway->createConnectAccount($creator);
    $creator->forceFill(['stripe_account_id' => $accountId])->save();
    $gateway->setAccount($accountId, payoutsEnabled: true, detailsSubmitted: true);

    postStripeEvent('evt_acct', 'account.updated', ['id' => $accountId], connect: true, extra: ['account' => $accountId])->assertOk();

    expect($creator->fresh()->canAcceptSupports())->toBeTrue()
        ->and(StripeEvent::findOrFail('evt_acct')->processed_at)->not->toBeNull();
});

it('rejects the platform secret on the connect endpoint', function () {
    fakeBilling();

    postStripeEvent('evt_x', 'account.updated', ['id' => 'acct_1'], connect: true, secret: 'whsec_platform')->assertStatus(400);

    expect(StripeEvent::count())->toBe(0);
});

it('rejects the connect secret on the platform endpoint', function () {
    fakeBilling();

    postStripeEvent('evt_y', 'invoice.paid', ['id' => 'in_1'], secret: 'whsec_connect')->assertStatus(400);

    expect(StripeEvent::count())->toBe(0);
});

it('stops accepting supports on deauthorization', function () {
    fakeBilling();
    $creator = Creator::factory()->payable()->create();
    $accountId = (string) $creator->stripe_account_id;
    expect($creator->canAcceptSupports())->toBeTrue();

    postStripeEvent('evt_deauth', 'account.application.deauthorized', ['id' => 'ca_1'], connect: true, extra: ['account' => $accountId])->assertOk();

    expect($creator->fresh()->canAcceptSupports())->toBeFalse();
});

it('acknowledges unknown events', function () {
    fakeBilling();

    postStripeEvent('evt_unknown', 'customer.created', ['id' => 'cus_1'])->assertOk();

    expect(StripeEvent::findOrFail('evt_unknown')->processed_at)->not->toBeNull();
});

it('leaves the event unprocessed when handling fails', function () {
    fakeBilling();

    // The fake gateway does not know this subscription, so retrieving it throws (the sync queue surfaces it to the caller).
    postStripeEvent('evt_boom', 'customer.subscription.updated', ['id' => 'sub_missing'])->assertStatus(500);

    expect(StripeEvent::findOrFail('evt_boom')->processed_at)->toBeNull();

    // Stripe's redelivery is not swallowed as a replay: it is attempted again (and fails again here).
    postStripeEvent('evt_boom', 'customer.subscription.updated', ['id' => 'sub_missing'])->assertStatus(500);

    expect(StripeEvent::count())->toBe(1)
        ->and(StripeEvent::findOrFail('evt_boom')->processed_at)->toBeNull();
});

it('records a refund on charge.refunded', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->price(800)->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);
    postStripeEvent('evt_paid', 'invoice.paid', ['id' => $invoiceId])->assertOk();
    $chargeId = $gateway->chargeIdForInvoice($invoiceId);
    $gateway->refundInvoice($invoiceId);

    postStripeEvent('evt_refund', 'charge.refunded', ['id' => $chargeId, 'object' => 'charge'])->assertOk();

    expect(Payment::sole()->refunded_amount)->toBe(800)
        ->and(Payment::sole()->refunded_at)->not->toBeNull()
        ->and(StripeEvent::findOrFail('evt_refund')->processed_at)->not->toBeNull();
});

it('records a dispute on charge.dispute.created using the disputed charge', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);
    postStripeEvent('evt_paid', 'invoice.paid', ['id' => $invoiceId])->assertOk();
    $chargeId = $gateway->chargeIdForInvoice($invoiceId);
    $gateway->markDisputed($chargeId);

    postStripeEvent('evt_dispute', 'charge.dispute.created', ['id' => 'du_1', 'object' => 'dispute', 'charge' => $chargeId])->assertOk();

    expect(Payment::sole()->disputed_at)->not->toBeNull()
        ->and(Payment::sole()->refunded_amount)->toBe(0);
});

it('passes the charge id of charge events to the job', function (string $type, array $object) {
    Queue::fake();

    postStripeEvent('evt_charge', $type, $object)->assertOk();

    Queue::assertPushed(ProcessStripeEvent::class, fn (ProcessStripeEvent $job) => $job->type === $type && $job->chargeId === 'ch_1');
})->with([
    'refund' => ['charge.refunded', ['id' => 'ch_1', 'object' => 'charge']],
    'dispute' => ['charge.dispute.created', ['id' => 'du_1', 'object' => 'dispute', 'charge' => 'ch_1']],
]);

/**
 * A pending order for 2 of a product (stock 5 → 3) with its open checkout session; returns [order, product, session ID].
 *
 * @return array{0: Order, 1: Product, 2: string}
 */
function webhookOrder(FakeGateway $gateway): array
{
    $creator = Creator::factory()->payable()->create(['shipping_fee' => 500]);
    $buyer = User::factory()->create();
    $product = Product::factory()->for($creator)->create(['price' => 1200, 'stock' => 5]);
    $cart = collect([CartItem::query()->create(['user_id' => $buyer->id, 'product_variant_id' => $product->variants()->value('id'), 'quantity' => 2])]);
    $order = app(Orders::class)->reserve($buyer, $creator, $cart);

    return [$order, $product, fakeOrderCheckout($gateway, $order)];
}

it('marks an order paid on checkout.session.completed', function () {
    $gateway = fakeBilling();
    [$order, , $sessionId] = webhookOrder($gateway);
    $gateway->completePaymentCheckout($sessionId);

    postStripeEvent('evt_o1', 'checkout.session.completed', ['id' => $sessionId, 'subscription' => null])->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(StripeEvent::findOrFail('evt_o1')->processed_at)->not->toBeNull();
});

it('cancels an order on checkout.session.expired', function () {
    $gateway = fakeBilling();
    [$order, $product, $sessionId] = webhookOrder($gateway);
    $gateway->expireCheckoutSession($sessionId);

    postStripeEvent('evt_o2', 'checkout.session.expired', ['id' => $sessionId])->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Canceled)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::Expired)
        ->and($product->refresh()->stock)->toBe(5);
});

it('settles an order on async_payment_succeeded and cancels on async_payment_failed', function () {
    $gateway = fakeBilling();
    [$paid, , $paidSession] = webhookOrder($gateway);
    [$failed, $product, $failedSession] = webhookOrder($gateway);
    $gateway->completePaymentCheckout($paidSession, paid: false);
    $gateway->completePaymentCheckout($failedSession, paid: false);
    postStripeEvent('evt_o3a', 'checkout.session.completed', ['id' => $paidSession, 'subscription' => null])->assertOk();
    expect($paid->refresh()->status)->toBe(OrderStatus::Pending);

    $gateway->settleAsyncPayment($paidSession, true);
    $gateway->settleAsyncPayment($failedSession, false);
    postStripeEvent('evt_o3b', 'checkout.session.async_payment_succeeded', ['id' => $paidSession])->assertOk();
    postStripeEvent('evt_o3c', 'checkout.session.async_payment_failed', ['id' => $failedSession])->assertOk();

    expect($paid->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($failed->refresh()->status)->toBe(OrderStatus::Canceled)
        ->and($failed->cancel_reason)->toBe(OrderCancelReason::PaymentFailed)
        ->and($product->refresh()->stock)->toBe(3 + 2);
});

it('ignores a replayed order event', function () {
    $gateway = fakeBilling();
    [$order, $product, $sessionId] = webhookOrder($gateway);
    $gateway->completePaymentCheckout($sessionId);

    postStripeEvent('evt_o4', 'checkout.session.completed', ['id' => $sessionId, 'subscription' => null])->assertOk();
    $paidAt = $order->refresh()->paid_at;
    postStripeEvent('evt_o4', 'checkout.session.completed', ['id' => $sessionId, 'subscription' => null])->assertOk();
    // A different event about the same state changes nothing either.
    postStripeEvent('evt_o4b', 'checkout.session.completed', ['id' => $sessionId, 'subscription' => null])->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at->equalTo($paidAt))->toBeTrue()
        ->and($product->refresh()->stock)->toBe(3);
});

it('ignores a checkout session of another app', function () {
    $gateway = fakeBilling();
    [$order, , $sessionId] = webhookOrder($gateway);
    $foreign = fakeOrderCheckout($gateway, $order, ['app' => 'other-app', 'order_id' => (string) $order->id]);
    $gateway->completePaymentCheckout($foreign);

    postStripeEvent('evt_o5', 'checkout.session.completed', ['id' => $foreign, 'subscription' => null])->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(StripeEvent::findOrFail('evt_o5')->processed_at)->not->toBeNull();
});

it('syncs a refund made in the Stripe dashboard', function () {
    $gateway = fakeBilling();
    [$order, $product, $sessionId] = webhookOrder($gateway);
    $gateway->completePaymentCheckout($sessionId);
    $order = app(OrderSync::class)->syncSession($sessionId);
    expect($product->refresh()->stock)->toBe(3);
    $paymentIntentId = (string) $order?->stripe_payment_intent_id;
    $gateway->refundPaymentIntent($paymentIntentId, 'dashboard');

    postStripeEvent('evt_o6', 'charge.refunded', ['id' => $gateway->chargeIdForPaymentIntent($paymentIntentId)])->assertOk();

    expect($order?->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->refunded_amount)->toBe($order->total)
        ->and($product->refresh()->stock)->toBe(5);
});

it('keeps a refunded order refunded when the payment success arrives late', function () {
    $gateway = fakeBilling();
    [$order, $product, $sessionId] = webhookOrder($gateway);
    $gateway->completePaymentCheckout($sessionId, paid: false);
    postStripeEvent('evt_o7a', 'checkout.session.completed', ['id' => $sessionId, 'subscription' => null])->assertOk();
    $gateway->settleAsyncPayment($sessionId, true);
    $paymentIntentId = (string) $order->refresh()->stripe_payment_intent_id;
    $gateway->refundPaymentIntent($paymentIntentId, 'dashboard');

    postStripeEvent('evt_o7b', 'charge.refunded', ['id' => $gateway->chargeIdForPaymentIntent($paymentIntentId)])->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($product->refresh()->stock)->toBe(5);

    postStripeEvent('evt_o7c', 'checkout.session.async_payment_succeeded', ['id' => $sessionId])->assertOk();
    postStripeEvent('evt_o7d', 'checkout.session.async_payment_succeeded', ['id' => $sessionId])->assertOk();

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded)
        ->and($order->paid_at)->toBeNull()
        ->and($product->refresh()->stock)->toBe(5);
});

it('still ignores a charge that belongs to neither a payment nor an order', function () {
    $gateway = fakeBilling();
    [$order, , $sessionId] = webhookOrder($gateway);
    $gateway->completePaymentCheckout($sessionId);

    postStripeEvent('evt_o8', 'charge.refunded', ['id' => $gateway->chargeIdForPaymentIntent((string) $gateway->retrieveCheckoutSession($sessionId)->paymentIntentId)])->assertOk();

    // The order was never synced, so it has no payment intent on it: nothing to attribute the charge to.
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});
