<?php

use App\Billing\BillingException;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = User::factory()->creator()->create();
    $this->plan = Plan::factory()->price(1000)->create(['name' => 'しっかり応援']);
    $this->fan = User::factory()->create();
});

/**
 * An active billing support of the fan, synced from the fake gateway.
 */
function syncedBillingSupport(FakeGateway $gateway, Plan $plan, User $fan): Support
{
    $support = app(SubscriptionSync::class)->apply(fakeCheckout($gateway, $plan, $fan));
    expect($support)->not->toBeNull()->and($support->status)->toBe(SupportStatus::Active);

    return $support;
}

it('lists the billing supports in the deletion dialog', function () {
    syncedBillingSupport($this->gateway, $this->plan, $this->fan);

    $this->actingAs($this->fan)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('billingSupports', [['plan' => 'しっかり応援', 'price' => 1000]]));
});

it('cancels the billing support immediately and deletes the user', function (bool $scheduledToStop) {
    $support = syncedBillingSupport($this->gateway, $this->plan, $this->fan);
    $this->gateway->setCancelAtPeriodEnd((string) $support->stripe_subscription_id, $scheduledToStop);

    $this->actingAs($this->fan)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($this->fan->fresh())->toBeNull()
        ->and($this->gateway->cancelledNow())->toBe([$support->stripe_subscription_id])
        ->and($this->gateway->retrieveSubscription((string) $support->stripe_subscription_id)->status)->toBe('canceled');
})->with(['renewing' => [false], 'scheduled to stop' => [true]]);

it('aborts deletion when stripe fails', function () {
    $support = syncedBillingSupport($this->gateway, $this->plan, $this->fan);

    $failing = Mockery::mock($this->gateway)->makePartial();
    $failing->shouldReceive('cancelSubscriptionNow')->andThrow(new BillingException('Stripe is down.'));
    app()->instance(PaymentGateway::class, $failing);

    $this->actingAs($this->fan)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'error',
            'message' => '解約の処理に失敗したため、アカウントを削除できませんでした。時間をおいてお試しください。',
        ]);

    $this->assertAuthenticatedAs($this->fan);
    expect($this->fan->fresh())->not->toBeNull()
        ->and($support->fresh()->status)->toBe(SupportStatus::Active);
});

it('deletes the user when a subscription was already cancelled at stripe', function () {
    $support = syncedBillingSupport($this->gateway, $this->plan, $this->fan);
    $this->gateway->setSubscriptionStatus((string) $support->stripe_subscription_id, 'canceled');

    $failing = Mockery::mock($this->gateway)->makePartial();
    $failing->shouldReceive('cancelSubscriptionNow')->andThrow(new BillingException('No such subscription to cancel.'));
    app()->instance(PaymentGateway::class, $failing);

    $this->actingAs($this->fan)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($this->fan->fresh())->toBeNull();
});

it('deletes a user with only dummy supports without calling stripe', function () {
    Support::factory()->forPlan($this->plan)->create(['user_id' => $this->fan->id]);

    $this->actingAs($this->fan)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect($this->fan->fresh())->toBeNull()
        ->and($this->gateway->cancelledNow())->toBe([]);
});

it('refuses deleting the creator while a support is active, even one scheduled to stop', function (array $state) {
    Support::factory()->forPlan($this->plan)->create($state);

    $this->actingAs($this->creator)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => '有効な支援者がいるため削除できません。すべてのプランの受付を停止して支援の期間が終わるのを待つか、Stripe ダッシュボードでサブスクリプションを解約してください。'])
        ->assertRedirect(route('profile.edit'));

    expect($this->creator->fresh())->not->toBeNull()
        ->and($this->gateway->cancelledNow())->toBe([]);
})->with([
    'cancel-scheduled billing' => [['stripe_subscription_id' => 'sub_x', 'stripe_status' => 'active', 'cancel_at_period_end' => true]],
    'dummy' => [[]],
]);
