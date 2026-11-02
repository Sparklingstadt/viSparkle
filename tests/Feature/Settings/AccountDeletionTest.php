<?php

use App\Billing\BillingException;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create(['display_name' => '星野']);
    $this->plan = Plan::factory()->for($this->creator)->price(1000)->create();
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
    Support::factory()->forPlan(Plan::factory()->price(500)->create())->create(['user_id' => $this->fan->id]);

    $this->actingAs($this->fan)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('billingSupports', [['creator' => '星野', 'price' => 1000]]));
});

it('cancels billing supports immediately and deletes the user', function () {
    $renewing = syncedBillingSupport($this->gateway, $this->plan, $this->fan);
    $otherCreator = Creator::factory()->payable()->create();
    $cancelling = syncedBillingSupport($this->gateway, Plan::factory()->for($otherCreator)->create(), $this->fan);
    $this->gateway->setCancelAtPeriodEnd((string) $cancelling->stripe_subscription_id, true);

    $this->actingAs($this->fan)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($this->fan->fresh())->toBeNull()
        ->and($this->gateway->cancelledNow())->toEqualCanonicalizing([$renewing->stripe_subscription_id, $cancelling->stripe_subscription_id])
        ->and($this->gateway->retrieveSubscription((string) $renewing->stripe_subscription_id)->status)->toBe('canceled');
});

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

it('keeps the user when a later cancellation fails, and deletes on retry', function () {
    $first = syncedBillingSupport($this->gateway, $this->plan, $this->fan);
    $second = syncedBillingSupport($this->gateway, Plan::factory()->for(Creator::factory()->payable())->create(), $this->fan);

    $fake = $this->gateway;
    $failing = Mockery::mock($fake)->makePartial();
    $failing->shouldReceive('cancelSubscriptionNow')->andReturnUsing(function (string $id) use ($fake, $second): void {
        if ($id === $second->stripe_subscription_id) {
            throw new BillingException('Stripe is down.');
        }

        $fake->cancelSubscriptionNow($id);
    });
    app()->instance(PaymentGateway::class, $failing);

    $this->actingAs($this->fan)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($this->fan->fresh())->not->toBeNull()
        ->and($first->fresh()->status)->toBe(SupportStatus::Ended)
        ->and($second->fresh()->status)->toBe(SupportStatus::Active);

    app()->instance(PaymentGateway::class, $fake);

    $this->actingAs($this->fan)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    expect($this->fan->fresh())->toBeNull()
        ->and($fake->cancelledNow())->toEqualCanonicalizing([$first->stripe_subscription_id, $second->stripe_subscription_id]);
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

it('refuses deleting a creator with an active support, even one scheduled to stop', function (array $state) {
    Support::factory()->forPlan($this->plan)->create($state);

    $this->actingAs($this->creator->user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => '有効な支援者がいるため削除できません。『活動を終了する』で支援を停止し、期間の終了をお待ちください。'])
        ->assertRedirect(route('profile.edit'));

    expect($this->creator->user->fresh())->not->toBeNull()
        ->and($this->gateway->cancelledNow())->toBe([]);
})->with([
    'cancel-scheduled billing' => [['stripe_subscription_id' => 'sub_x', 'stripe_status' => 'active', 'cancel_at_period_end' => true]],
    'dummy' => [[]],
]);

it('refuses deleting a creator with an order not yet shipped or still awaiting payment', function (string $state) {
    $order = Order::factory()->for($this->creator);
    ($state === 'paid' ? $order->paid() : $order)->create();

    $this->actingAs($this->creator->user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors(['password' => '未発送または支払い待ちの注文があるため、アカウントを削除できません。'])
        ->assertRedirect(route('profile.edit'));

    expect($this->creator->user->fresh())->not->toBeNull();
})->with(['paid', 'pending']);

it('deletes a creator whose orders are all shipped, canceled or refunded', function () {
    Order::factory()->for($this->creator)->shipped()->create();
    Order::factory()->for($this->creator)->create(['status' => OrderStatus::Canceled]);
    Order::factory()->for($this->creator)->paid()->create(['status' => OrderStatus::Refunded]);
    $user = $this->creator->user;

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
});
