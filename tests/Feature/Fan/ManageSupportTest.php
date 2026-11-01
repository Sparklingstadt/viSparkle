<?php

use App\Billing\BillingException;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use App\Services\Entitlement;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create();
    $this->low = Plan::factory()->for($this->creator)->price(1000)->create();
    $this->high = Plan::factory()->for($this->creator)->price(3000)->create();
    $this->fan = User::factory()->create();
});

/**
 * Support the plan through the whole checkout flow over HTTP and return the active billing support.
 */
function billingSupport(User $fan, Plan $plan): Support
{
    $response = test()->actingAs($fan)->post(route('supports.store', [$plan->creator, $plan]));
    $sessionId = basename((string) $response->headers->get('Location'));
    app(FakeGateway::class)->completeCheckout($sessionId);
    test()->actingAs($fan)->get(route('supports.checkout.complete', ['session_id' => $sessionId]));

    $support = Support::query()->where('user_id', $fan->id)->latest('id')->firstOrFail();
    expect($support->isBilling())->toBeTrue()->and($support->status)->toBe(SupportStatus::Active);

    return $support;
}

it('schedules cancellation and keeps access until the period ends', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(1000)->create();
    $support = billingSupport($this->fan, $this->low);

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', [
            'type' => 'success',
            'message' => '支援の停止を予約しました。'.$support->fresh()->current_period_end->format('Y年n月j日').'まで閲覧できます。',
        ]);

    $support->refresh();
    expect($support->cancel_at_period_end)->toBeTrue()
        ->and($support->status)->toBe(SupportStatus::Active)
        ->and(Entitlement::for($this->fan)->canView($post))->toBeTrue();

    $this->gateway->advancePeriod((string) $support->stripe_subscription_id);
    app(SubscriptionSync::class)->sync((string) $support->stripe_subscription_id);

    expect($support->fresh()->status)->toBe(SupportStatus::Ended)
        ->and(Entitlement::for($this->fan)->canView($post))->toBeFalse();
});

it('resumes a scheduled cancellation', function () {
    $support = billingSupport($this->fan, $this->low);
    $this->actingAs($this->fan)->delete(route('supports.destroy', $support));

    $this->actingAs($this->fan)->post(route('supports.resume', $support))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '支援を再開しました。']);

    expect($support->fresh()->cancel_at_period_end)->toBeFalse()
        ->and($this->gateway->retrieveSubscription((string) $support->stripe_subscription_id)->cancelAtPeriodEnd)->toBeFalse();
});

it('refuses resuming a support that is not cancelling', function () {
    $support = billingSupport($this->fan, $this->low);

    $this->actingAs($this->fan)->post(route('supports.resume', $support))
        ->assertSessionHasErrors(['plan' => '再開できる支援がありません。']);

    $dummy = Support::factory()->for(User::factory()->create())->forPlan($this->low)->active()->create();
    $this->actingAs($dummy->user)->post(route('supports.resume', $dummy))
        ->assertSessionHasErrors(['plan' => '再開できる支援がありません。']);
});

it('404s resuming someone elses support', function () {
    $support = billingSupport($this->fan, $this->low);

    $this->actingAs(User::factory()->create())->post(route('supports.resume', $support))->assertNotFound();
});

it('refuses stopping a support that already ended', function () {
    $support = Support::factory()->for($this->fan)->forPlan($this->low)->ended()->create();

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))
        ->assertSessionHasErrors(['plan' => 'この支援はすでに終了しています。']);
});

it('ends a dummy support immediately', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(1000)->create();
    $support = Support::factory()->for($this->fan)->forPlan($this->low)->active()->create();

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '支援を停止しました。']);

    expect($support->fresh()->status)->toBe(SupportStatus::Ended)
        ->and($support->fresh()->ended_at)->not->toBeNull()
        ->and(Entitlement::for($this->fan)->canView($post))->toBeFalse();
});

it('404s ending someone elses support', function () {
    $support = Support::factory()->for(User::factory()->create())->forPlan($this->low)->active()->create();

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))->assertNotFound();
    expect($support->fresh()->status)->toBe(SupportStatus::Active);
});

it('upgrades and charges the difference', function () {
    $support = billingSupport($this->fan, $this->low);
    $subscriptionId = (string) $support->stripe_subscription_id;

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $this->high->id])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'プランを変更しました。']);

    $support->refresh();
    expect($support->plan_id)->toBe($this->high->id)
        ->and($support->price)->toBe(3000);

    $invoice = $this->gateway->retrieveInvoice((string) $this->gateway->latestInvoiceId($subscriptionId));
    expect($invoice->status)->toBe('paid')->and($invoice->amountPaid)->toBe(2000);
});

it('keeps the old plan when the upgrade payment fails', function () {
    $support = billingSupport($this->fan, $this->low);
    $this->gateway->failPayments();

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $this->high->id])
        ->assertSessionHasErrors(['plan' => 'お支払いに失敗しました。カード情報をご確認ください。']);

    $support->refresh();
    expect($support->plan_id)->toBe($this->low->id)
        ->and($support->price)->toBe(1000);
});

it('downgrades without charging', function () {
    $support = billingSupport($this->fan, $this->high);
    $subscriptionId = (string) $support->stripe_subscription_id;
    $invoiceBefore = $this->gateway->latestInvoiceId($subscriptionId);

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $this->low->id])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'プランを変更しました。']);

    $support->refresh();
    expect($support->plan_id)->toBe($this->low->id)
        ->and($support->price)->toBe(1000)
        ->and($this->gateway->latestInvoiceId($subscriptionId))->toBe($invoiceBefore);
});

it('ensures the new price exists before changing', function () {
    $support = billingSupport($this->fan, $this->low);
    $fresh = Plan::factory()->for($this->creator)->price(5000)->create();
    expect($fresh->stripe_price_id)->toBeNull();

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $fresh->id])->assertRedirect();

    expect($fresh->fresh()->stripe_price_id)->not->toBeNull()
        ->and($support->fresh()->plan_id)->toBe($fresh->id);
});

it('refuses changing to the same plan', function () {
    $support = billingSupport($this->fan, $this->low);

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $this->low->id])
        ->assertSessionHasErrors(['plan' => 'すでにこのプランで支援中です。']);
});

it('refuses changing while cancellation is scheduled', function () {
    $support = billingSupport($this->fan, $this->low);
    $this->actingAs($this->fan)->delete(route('supports.destroy', $support));

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $this->high->id])
        ->assertSessionHasErrors(['plan' => '停止予約中のため変更できません。先に支援を再開してください。']);

    expect($support->fresh()->plan_id)->toBe($this->low->id);
});

it('refuses changing to a plan that is not accepting', function () {
    $support = billingSupport($this->fan, $this->low);
    $closed = Plan::factory()->for($this->creator)->price(2000)->notAccepting()->create();

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $closed->id])
        ->assertSessionHasErrors(['plan' => 'このプランは現在受付を停止しています。']);

    expect($support->fresh()->plan_id)->toBe($this->low->id);
});

it('refuses changing a dummy support', function () {
    $support = Support::factory()->for($this->fan)->forPlan($this->low)->active()->create();

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $this->high->id])
        ->assertSessionHasErrors(['plan' => 'この支援はプラン変更に対応していません。停止してから支援し直してください。']);
});

it('404s on another creators plan', function () {
    $support = billingSupport($this->fan, $this->low);
    $other = Plan::factory()->price(2000)->create();

    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => $other->id])->assertNotFound();
    $this->actingAs($this->fan)->put(route('supports.update', $support), ['plan_id' => 999999])->assertNotFound();
    $this->actingAs($this->fan)->put(route('supports.update', $support), [])->assertSessionHasErrors('plan_id');
});

it('404s changing someone elses support', function () {
    $support = billingSupport($this->fan, $this->low);

    $this->actingAs(User::factory()->create())
        ->put(route('supports.update', $support), ['plan_id' => $this->high->id])
        ->assertNotFound();
});

it('shows a toast when stripe is unreachable while managing', function () {
    $support = billingSupport($this->fan, $this->low);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('setCancelAtPeriodEnd')->andThrow(new BillingException('down'));
    app()->instance(PaymentGateway::class, $gateway);

    $this->actingAs($this->fan)->from(route('supporting.index'))->delete(route('supports.destroy', $support))
        ->assertRedirect(route('supporting.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

    expect($support->fresh()->cancel_at_period_end)->toBeFalse();
});

it('opens the customer portal', function () {
    billingSupport($this->fan, $this->low);

    $response = $this->actingAs($this->fan)->post(route('billing.portal'));

    $response->assertRedirect(route('supporting.index'));
});

it('answers the portal request of an inertia visit with a location redirect', function () {
    billingSupport($this->fan, $this->low);

    $response = $this->actingAs($this->fan)->post(route('billing.portal'), [], ['X-Inertia' => 'true']);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toBe(route('supporting.index'));
});

it('refuses the portal without a billing account', function () {
    $this->actingAs($this->fan)->from(route('supporting.index'))->post(route('billing.portal'))
        ->assertRedirect(route('supporting.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'お支払い情報がまだありません。']);
});

it('lists billing details on the supporting page', function () {
    $support = billingSupport($this->fan, $this->low);
    $this->actingAs($this->fan)->delete(route('supports.destroy', $support));
    $dummy = Support::factory()->for($this->fan)->forPlan(Plan::factory()->price(500)->create())->active()->create(['started_at' => now()->addHour()]);

    $this->actingAs($this->fan)->get(route('supporting.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fan/supporting')
            ->where('hasBillingAccount', true)
            ->has('supports', 2)
            ->where('supports.0.id', $dummy->id)
            ->where('supports.0.is_billing', false)
            ->where('supports.0.cancel_at_period_end', false)
            ->where('supports.0.current_period_end', null)
            ->where('supports.1.id', $support->id)
            ->where('supports.1.is_billing', true)
            ->where('supports.1.stripe_status', 'active')
            ->where('supports.1.cancel_at_period_end', true)
            ->where('supports.1.current_period_end', $support->fresh()->current_period_end->toJSON()));
});

it('reports no billing account for a fan without a customer', function () {
    Support::factory()->for($this->fan)->forPlan($this->low)->active()->create();

    $this->actingAs($this->fan)->get(route('supporting.index'))
        ->assertInertia(fn ($page) => $page->where('hasBillingAccount', false));
});

it('rate limits support operations', function () {
    $support = billingSupport($this->fan, $this->low);

    $statuses = [];

    foreach (range(1, 12) as $i) {
        $statuses[] = $this->actingAs($this->fan)->post(route('supports.resume', $support))->status();
    }

    expect($statuses)->toContain(429);
});
