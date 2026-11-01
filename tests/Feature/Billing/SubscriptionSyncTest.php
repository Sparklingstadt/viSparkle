<?php

use App\Billing\Data\SubscriptionData;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create();
    $this->plan = Plan::factory()->for($this->creator)->price(500)->create();
    $this->fan = User::factory()->create();
});

it('creates an active support from a completed checkout', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);

    $support = app(SubscriptionSync::class)->sync($subscription->id);

    expect($support)->not->toBeNull()
        ->and($support->user_id)->toBe($this->fan->id)
        ->and($support->creator_id)->toBe($this->creator->id)
        ->and($support->plan_id)->toBe($this->plan->id)
        ->and($support->price)->toBe(500)
        ->and($support->status)->toBe(SupportStatus::Active)
        ->and($support->stripe_subscription_id)->toBe($subscription->id)
        ->and($support->stripe_status)->toBe('active')
        ->and($support->cancel_at_period_end)->toBeFalse()
        ->and($support->started_at)->not->toBeNull()
        ->and($support->ended_at)->toBeNull()
        ->and($support->fresh()->current_period_end->timestamp)->toBe($subscription->currentPeriodEnd->timestamp);
});

it('maps stripe statuses', function (string $stripeStatus, SupportStatus $status) {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $sync->sync($subscription->id);

    $this->gateway->setSubscriptionStatus($subscription->id, $stripeStatus);
    $support = $sync->sync($subscription->id);

    expect($support->status)->toBe($status)
        ->and($support->stripe_status)->toBe($stripeStatus)
        ->and($support->ended_at === null)->toBe($status === SupportStatus::Active)
        ->and(Support::count())->toBe(1);
})->with([
    'past_due' => ['past_due', SupportStatus::Active],
    'canceled' => ['canceled', SupportStatus::Ended],
    'unpaid' => ['unpaid', SupportStatus::Ended],
    'trialing' => ['trialing', SupportStatus::Active],
    'incomplete_expired' => ['incomplete_expired', SupportStatus::Ended],
    'paused' => ['paused', SupportStatus::Ended],
]);

it('reactivates when stripe moves back to active', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $startedAt = $sync->sync($subscription->id)->started_at;

    $this->gateway->setSubscriptionStatus($subscription->id, 'unpaid');
    expect($sync->sync($subscription->id)->status)->toBe(SupportStatus::Ended);

    $this->travel(1)->day();
    $this->gateway->setSubscriptionStatus($subscription->id, 'active');
    $support = $sync->sync($subscription->id);

    expect($support->status)->toBe(SupportStatus::Active)
        ->and($support->ended_at)->toBeNull()
        ->and($support->started_at->timestamp)->toBe($startedAt->timestamp)
        ->and(Support::count())->toBe(1);
});

it('keeps the original end time when an ended support ends again', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $sync->sync($subscription->id);

    $this->gateway->setSubscriptionStatus($subscription->id, 'unpaid');
    $endedAt = $sync->sync($subscription->id)->ended_at;

    $this->travel(3)->days();
    $this->gateway->setSubscriptionStatus($subscription->id, 'canceled');
    $support = $sync->sync($subscription->id);

    expect($support->status)->toBe(SupportStatus::Ended)
        ->and($support->stripe_status)->toBe('canceled')
        ->and($support->fresh()->ended_at->timestamp)->toBe($endedAt->timestamp);
});

it('converges regardless of call order', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);

    $sync->sync($subscription->id);
    $sync->sync($subscription->id);
    $sync->sync($subscription->id);
    expect(Support::count())->toBe(1);

    $this->gateway->setSubscriptionStatus($subscription->id, 'canceled');
    $sync->sync($subscription->id);
    $sync->sync($subscription->id);

    expect(Support::count())->toBe(1)
        ->and(Support::sole()->status)->toBe(SupportStatus::Ended);
});

it('records a subscription first seen as ended', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $this->gateway->setSubscriptionStatus($subscription->id, 'canceled');

    $support = app(SubscriptionSync::class)->sync($subscription->id);

    expect($support->status)->toBe(SupportStatus::Ended)
        ->and($support->ended_at)->not->toBeNull()
        ->and($support->started_at)->not->toBeNull();
});

it('ignores subscriptions whose metadata does not match the customer', function () {
    Log::spy();
    $other = User::factory()->create();
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan, [
        'user_id' => (string) $other->id,
        'creator_id' => (string) $this->creator->id,
        'plan_id' => (string) $this->plan->id,
    ]);

    expect(app(SubscriptionSync::class)->sync($subscription->id))->toBeNull()
        ->and(Support::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.metadata_mismatch', Mockery::type('array'))->once();
});

it('ignores subscriptions with missing metadata', function () {
    Log::spy();
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan, ['user_id' => (string) $this->fan->id]);

    expect(app(SubscriptionSync::class)->sync($subscription->id))->toBeNull()
        ->and(Support::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.metadata_mismatch', Mockery::type('array'))->once();
});

it('ignores a new subscription whose metadata plan belongs to another creator', function () {
    Log::spy();
    $foreign = Plan::factory()->for(Creator::factory())->create();
    $foreignPrice = $this->gateway->ensurePrice($foreign)->priceId;
    // Checked out at the other creator's price, with metadata naming this creator and the other creator's plan.
    $subscription = fakeCheckout($this->gateway, $foreign, $this->fan, [
        'user_id' => (string) $this->fan->id,
        'creator_id' => (string) $this->creator->id,
        'plan_id' => (string) $foreign->id,
    ]);

    expect($subscription->priceId)->toBe($foreignPrice)
        ->and(app(SubscriptionSync::class)->sync($subscription->id))->toBeNull()
        ->and(Support::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.metadata_mismatch', Mockery::type('array'))->once();
});

it('uses the price plan even when the metadata plan is stale', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan, [
        'user_id' => (string) $this->fan->id,
        'creator_id' => (string) $this->creator->id,
        'plan_id' => (string) Plan::factory()->for(Creator::factory())->create()->id,
    ]);

    expect(app(SubscriptionSync::class)->sync($subscription->id)->plan_id)->toBe($this->plan->id);
});

it('ignores an existing support whose user no longer owns the customer', function () {
    Log::spy();
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $sync->sync($subscription->id);

    $this->fan->forceFill(['stripe_customer_id' => 'cus_someone_else'])->save();
    $this->gateway->setSubscriptionStatus($subscription->id, 'canceled');

    expect($sync->sync($subscription->id))->toBeNull()
        ->and(Support::sole()->status)->toBe(SupportStatus::Active);
    Log::shouldHaveReceived('warning')->with('billing.metadata_mismatch', Mockery::type('array'))->once();
});

it('cancels a duplicate subscription for the same creator', function () {
    Log::spy();
    $first = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $second = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);

    $support = $sync->sync($first->id);
    $result = $sync->sync($second->id);

    expect($result->id)->toBe($support->id)
        ->and($result->stripe_subscription_id)->toBe($first->id)
        ->and($this->gateway->cancelledNow())->toBe([$second->id])
        ->and(Support::count())->toBe(1);
    Log::shouldHaveReceived('error')->with('billing.duplicate_subscription', [
        'subscription' => $second->id,
        'user_id' => $this->fan->id,
        'creator_id' => $this->creator->id,
    ])->once();
});

it('treats an existing non-billing support as the active one', function () {
    Log::spy();
    $existing = Support::factory()->forPlan($this->plan)->for($this->fan)->create();
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);

    $result = app(SubscriptionSync::class)->sync($subscription->id);

    expect($result->id)->toBe($existing->id)
        ->and($this->gateway->cancelledNow())->toBe([$subscription->id])
        ->and(Support::count())->toBe(1);
});

it('returns the existing support even when cancelling the duplicate fails', function () {
    Log::spy();
    $first = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $support = $sync->sync($first->id);

    // The gateway does not know this subscription, so cancelling it throws BillingException.
    $result = $sync->apply(new SubscriptionData(
        id: 'sub_unknown',
        customerId: (string) $this->fan->stripe_customer_id,
        status: 'active',
        itemId: 'si_unknown',
        priceId: $this->plan->fresh()->stripe_price_id,
        unitAmount: 500,
        currentPeriodEnd: now()->toImmutable()->addMonth(),
        cancelAtPeriodEnd: false,
        metadata: ['user_id' => (string) $this->fan->id, 'creator_id' => (string) $this->creator->id, 'plan_id' => (string) $this->plan->id],
    ));

    expect($result->id)->toBe($support->id)
        ->and(Support::count())->toBe(1);
    Log::shouldHaveReceived('error')->with('billing.duplicate_subscription', Mockery::type('array'))->once();
    Log::shouldHaveReceived('error')->with('billing.duplicate_cancel_failed', Mockery::type('array'))->once();
});

it('follows a plan change made in stripe', function () {
    $other = Plan::factory()->for($this->creator)->price(1000)->create();
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $support = $sync->sync($subscription->id);

    $this->gateway->changeSubscriptionPrice($subscription->id, (string) $subscription->itemId, $this->gateway->ensurePrice($other)->priceId, false);
    $updated = $sync->sync($subscription->id);

    expect($updated->id)->toBe($support->id)
        ->and($updated->plan_id)->toBe($other->id)
        ->and($updated->price)->toBe(1000)
        ->and(Support::count())->toBe(1);
});

it('follows the scheduled cancellation flag', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $sync->sync($subscription->id);

    $support = $sync->apply($this->gateway->setCancelAtPeriodEnd($subscription->id, true));

    expect($support->cancel_at_period_end)->toBeTrue()
        ->and($support->status)->toBe(SupportStatus::Active);
});

it('ignores incomplete subscriptions', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $this->gateway->setSubscriptionStatus($subscription->id, 'incomplete');

    expect(app(SubscriptionSync::class)->sync($subscription->id))->toBeNull()
        ->and(Support::count())->toBe(0);
});

it('leaves an existing support unchanged when stripe reports incomplete', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $sync = app(SubscriptionSync::class);
    $sync->sync($subscription->id);

    $this->gateway->setSubscriptionStatus($subscription->id, 'incomplete');
    $support = $sync->sync($subscription->id);

    expect($support->status)->toBe(SupportStatus::Active)
        ->and($support->fresh()->stripe_status)->toBe('active');
});

it('cancels a new subscription whose user was deleted', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $this->fan->delete();
    Log::spy();

    expect(app(SubscriptionSync::class)->sync($subscription->id))->toBeNull()
        ->and(Support::query()->count())->toBe(0)
        ->and($this->gateway->cancelledNow())->toBe([$subscription->id]);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'billing.orphan_subscription' && $context['reason'] === 'user_missing')->once();
});

it('cancels a new subscription for a closed or suspended creator', function (string $reason) {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $reason === 'creator_closed'
        ? $this->creator->forceFill(['closed_at' => now()])->save()
        : $this->creator->user->forceFill(['suspended_at' => now()])->save();
    Log::spy();

    expect(app(SubscriptionSync::class)->sync($subscription->id))->toBeNull()
        ->and(Support::query()->count())->toBe(0)
        ->and($this->gateway->cancelledNow())->toBe([$subscription->id]);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'billing.orphan_subscription' && $context['reason'] === $reason)->once();
})->with(['creator_closed', 'creator_suspended']);

it('keeps syncing an existing support after its creator closed', function () {
    $support = app(SubscriptionSync::class)->sync(fakeCheckout($this->gateway, $this->plan, $this->fan)->id);
    $this->creator->forceFill(['closed_at' => now()])->save();

    expect(app(SubscriptionSync::class)->sync((string) $support->stripe_subscription_id)->status)->toBe(SupportStatus::Active)
        ->and($this->gateway->cancelledNow())->toBe([]);
});
