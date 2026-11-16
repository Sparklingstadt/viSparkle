<?php

use App\Billing\BillingException;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\SubscriptionData;
use App\Billing\FakeGateway;
use App\Billing\PaymentFailedException;
use App\Billing\PaymentGateway;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\User;
use App\Providers\BillingServiceProvider;
use Carbon\CarbonImmutable;

/**
 * Walk a fan through checkout of the given plan and return the resulting subscription.
 */
function subscribeFake(FakeGateway $gateway, Plan $plan, ?User $user = null): SubscriptionData
{
    $user ??= User::factory()->create();
    $price = $gateway->ensurePrice($plan);
    $session = $gateway->createCheckoutSession(new CheckoutRequest(
        customerId: $gateway->createCustomer($user),
        priceId: $price->priceId,
        destinationAccountId: $gateway->createConnectAccount($plan->creator),
        feePercent: 10,
        metadata: ['user_id' => (string) $user->id, 'creator_id' => (string) $plan->creator_id, 'plan_id' => (string) $plan->id],
        successUrl: 'https://app.test/success',
        cancelUrl: 'https://app.test/cancel',
        expiresAt: CarbonImmutable::now()->addMinutes(30),
    ));

    return $gateway->completeCheckout($session->id);
}

it('completes a checkout into an active subscription carrying metadata', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory())->price(500)->create();
    $user = User::factory()->create();

    $subscription = subscribeFake($gateway, $plan, $user);

    expect($subscription->status)->toBe('active')
        ->and($subscription->priceId)->toBe($gateway->ensurePrice($plan)->priceId)
        ->and($subscription->unitAmount)->toBe(500)
        ->and($subscription->cancelAtPeriodEnd)->toBeFalse()
        ->and($subscription->metadata['user_id'])->toBe((string) $user->id)
        ->and($subscription->currentPeriodEnd->diffInDays(now()->addMonth(), true))->toBeLessThan(1)
        ->and(abs($subscription->currentPeriodEnd->timestamp - now()->addMonth()->timestamp))->toBeLessThan(5)
        ->and($subscription->currentPeriodEnd->timezone->getName())->toBe(config('app.timezone'));

    $invoice = $gateway->retrieveInvoice($gateway->latestInvoiceId($subscription->id));
    expect($invoice->amountPaid)->toBe(500)
        ->and($invoice->status)->toBe('paid')
        ->and($invoice->subscriptionId)->toBe($subscription->id)
        ->and(abs($invoice->paidAt->timestamp - now()->timestamp))->toBeLessThan(5)
        ->and($invoice->paidAt->timezone->getName())->toBe(config('app.timezone'));
});

it('schedules and undoes cancellation', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory())->price(500)->create();

    $cancelled = subscribeFake($gateway, $plan);
    expect($gateway->setCancelAtPeriodEnd($cancelled->id, true)->cancelAtPeriodEnd)->toBeTrue();
    expect($gateway->advancePeriod($cancelled->id))->toBeNull()
        ->and($gateway->retrieveSubscription($cancelled->id)->status)->toBe('canceled');

    $resumed = subscribeFake($gateway, $plan);
    $gateway->setCancelAtPeriodEnd($resumed->id, true);
    $gateway->setCancelAtPeriodEnd($resumed->id, false);
    $invoice = $gateway->advancePeriod($resumed->id);

    expect($invoice)->not->toBeNull()
        ->and($invoice->amountPaid)->toBe(500)
        ->and($gateway->retrieveSubscription($resumed->id)->status)->toBe('active')
        ->and($gateway->retrieveSubscription($resumed->id)->currentPeriodEnd->greaterThan($resumed->currentPeriodEnd))->toBeTrue();
});

it('does not apply an upgrade when payment fails', function () {
    $gateway = fakeBilling();
    $creator = Creator::factory()->create();
    $low = Plan::factory()->for($creator)->price(300)->create();
    $high = Plan::factory()->for($creator)->price(1000)->create();
    $subscription = subscribeFake($gateway, $low);
    $highPrice = $gateway->ensurePrice($high);

    $gateway->failPayments();

    expect(fn () => $gateway->changeSubscriptionPrice($subscription->id, $subscription->itemId, $highPrice->priceId, true))
        ->toThrow(PaymentFailedException::class);
    expect($gateway->retrieveSubscription($subscription->id)->priceId)->toBe($subscription->priceId);
});

it('invoices the difference on upgrade and nothing on downgrade', function () {
    $gateway = fakeBilling();
    $creator = Creator::factory()->create();
    $low = Plan::factory()->for($creator)->price(300)->create();
    $high = Plan::factory()->for($creator)->price(1000)->create();
    $subscription = subscribeFake($gateway, $low);

    $upgraded = $gateway->changeSubscriptionPrice($subscription->id, $subscription->itemId, $gateway->ensurePrice($high)->priceId, true);
    $upgradeInvoice = $gateway->retrieveInvoice($gateway->latestInvoiceId($subscription->id));
    expect($upgraded->unitAmount)->toBe(1000)
        ->and($upgradeInvoice->amountPaid)->toBe(700);

    $gateway->changeSubscriptionPrice($subscription->id, $subscription->itemId, $gateway->ensurePrice($low)->priceId, false);
    expect($gateway->latestInvoiceId($subscription->id))->toBe($upgradeInvoice->id)
        ->and($gateway->retrieveSubscription($subscription->id)->unitAmount)->toBe(300);
});

it('returns the same price for the same plan', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory())->create();

    $first = $gateway->ensurePrice($plan);

    expect($gateway->ensurePrice($plan))->toEqual($first)
        ->and($gateway->ensurePrice($plan->fresh()))->toEqual($first)
        ->and($plan->fresh()->stripe_price_id)->toBe($first->priceId)
        ->and($plan->fresh()->stripe_product_id)->toBe($first->productId)
        ->and($first->priceId)->toStartWith('price_fake_')
        ->and($first->productId)->toStartWith('prod_fake_');
});

it('marks an account payable after onboarding and exposes simple links', function () {
    $gateway = fakeBilling();
    $accountId = $gateway->createConnectAccount(Creator::factory()->create());

    expect($gateway->retrieveAccount($accountId)->payoutsEnabled)->toBeFalse();
    expect($gateway->createAccountOnboardingLink($accountId, 'https://app.test/refresh', 'https://app.test/return'))->toBe('https://app.test/return');

    $account = $gateway->retrieveAccount($accountId);
    expect($account->payoutsEnabled)->toBeTrue()
        ->and($account->detailsSubmitted)->toBeTrue()
        ->and($gateway->createDashboardLoginLink($accountId))->toEndWith('/creator/earnings');
});

it('tracks checkout session state and unknown ids', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory())->price(500)->create();
    $user = User::factory()->create();
    $request = new CheckoutRequest($gateway->createCustomer($user), $gateway->ensurePrice($plan)->priceId, 'acct_x', 10, [], 'https://a.test/s', 'https://a.test/c', now()->toImmutable()->addMinutes(30));

    $session = $gateway->createCheckoutSession($request);
    expect($session->status)->toBe('open')
        ->and($session->url)->toEndWith('/dev/fake-checkout/'.$session->id)
        ->and($gateway->describeSession($session->id))->toMatchArray(['amount' => 500, 'plan_id' => $plan->id, 'status' => 'open'])
        ->and($gateway->describeSession('nope'))->toBeNull();

    $gateway->expireCheckoutSession($session->id);
    expect($gateway->retrieveCheckoutSession($session->id)->status)->toBe('expired');

    expect(fn () => $gateway->retrieveSubscription('sub_fake_missing'))->toThrow(BillingException::class);
});

it('records immediate cancellations and sets past_due when renewal fails', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory())->price(500)->create();
    $subscription = subscribeFake($gateway, $plan);

    $gateway->failPayments();
    expect($gateway->advancePeriod($subscription->id))->toBeNull()
        ->and($gateway->retrieveSubscription($subscription->id)->status)->toBe('past_due');

    $gateway->cancelSubscriptionNow($subscription->id);
    expect($gateway->cancelledNow())->toBe([$subscription->id])
        ->and($gateway->retrieveSubscription($subscription->id)->status)->toBe('canceled');
});

it('refuses the fake driver in production', function () {
    config(['fanbox.billing.driver' => 'fake']);
    app()->detectEnvironment(fn () => 'production');
    app()->forgetInstance(PaymentGateway::class);

    expect(fn () => app(PaymentGateway::class))->toThrow(LogicException::class, 'The fake billing driver cannot be used in production.');
});

it('refuses the fake driver in production at boot', function () {
    config(['fanbox.billing.driver' => 'fake']);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new BillingServiceProvider(app()))->boot())->toThrow(LogicException::class, 'The fake billing driver cannot be used in production.');
});

it('boots with the fake driver outside production and with the stripe driver in production', function () {
    config(['fanbox.billing.driver' => 'fake']);
    (new BillingServiceProvider(app()))->boot();

    config(['fanbox.billing.driver' => 'stripe']);
    app()->detectEnvironment(fn () => 'production');
    (new BillingServiceProvider(app()))->boot();
})->throwsNoExceptions();
