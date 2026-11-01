<?php

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\SubscriptionSync;
use App\Services\Entitlement;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create();
    $this->plan = Plan::factory()->for($this->creator)->price(1000)->create();
    $this->other = Plan::factory()->for($this->creator)->price(3000)->create();
});

function closureSupport(Plan $plan): Support
{
    $support = app(SubscriptionSync::class)->apply(fakeCheckout(app(PaymentGateway::class), $plan, User::factory()->create()));
    expect($support)->not->toBeNull();

    return $support;
}

it('stops the plans and schedules every billing support to end with its period', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(1000)->create();
    $a = closureSupport($this->plan);
    $b = closureSupport($this->other);
    $alreadyCancelling = closureSupport($this->plan);
    $this->gateway->setCancelAtPeriodEnd((string) $alreadyCancelling->stripe_subscription_id, true);
    $alreadyCancelling->forceFill(['cancel_at_period_end' => true])->save();
    $dummy = Support::factory()->forPlan($this->plan)->create();

    $this->actingAs($this->creator->user)
        ->post(route('creator.closure.store'))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '活動を終了しました。2件の支援を期間の終わりで停止します。']);

    $this->creator->refresh();
    expect($this->creator->isClosed())->toBeTrue()
        ->and($this->creator->plans()->where('is_accepting', true)->exists())->toBeFalse()
        ->and($a->fresh()->cancel_at_period_end)->toBeTrue()
        ->and($a->fresh()->status)->toBe(SupportStatus::Active)
        ->and($b->fresh()->cancel_at_period_end)->toBeTrue()
        ->and($this->gateway->retrieveSubscription((string) $a->stripe_subscription_id)->cancelAtPeriodEnd)->toBeTrue()
        ->and($this->gateway->cancelledNow())->toBe([])
        ->and($dummy->fresh()->status)->toBe(SupportStatus::Ended)
        // Existing supporters keep access until their period ends.
        ->and(Entitlement::for($a->user)->canView($post))->toBeTrue();
});

it('counts failures, logs them and carries on', function () {
    $failed = closureSupport($this->plan);
    $ok = closureSupport($this->plan);

    $failing = Mockery::mock($this->gateway)->makePartial();
    $failing->shouldReceive('setCancelAtPeriodEnd')->with($failed->stripe_subscription_id, true)->andThrow(new BillingException('Stripe is down.'));
    app()->instance(PaymentGateway::class, $failing);
    Log::spy();

    $this->actingAs($this->creator->user)
        ->post(route('creator.closure.store'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'warning', 'message' => '活動を終了しました。1件の支援を期間の終わりで停止します。（1件は失敗しました。もう一度お試しください）']);

    expect($failed->fresh()->cancel_at_period_end)->toBeFalse()
        ->and($ok->fresh()->cancel_at_period_end)->toBeTrue()
        ->and($this->creator->fresh()->isClosed())->toBeTrue();
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'billing.ops_failed' && $context['support_id'] === $failed->id);
});

it('can be run again to retry the failed ones', function () {
    $support = closureSupport($this->plan);
    $this->actingAs($this->creator->user)->post(route('creator.closure.store'));

    $this->actingAs($this->creator->user)
        ->post(route('creator.closure.store'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '活動を終了しました。0件の支援を期間の終わりで停止します。']);

    expect($support->fresh()->cancel_at_period_end)->toBeTrue();
});

it('refuses resuming or changing the plan of a support to a closed creator', function () {
    $support = closureSupport($this->plan);
    $this->actingAs($this->creator->user)->post(route('creator.closure.store'));
    $this->plan->forceFill(['is_accepting' => true])->save();
    $this->other->forceFill(['is_accepting' => true])->save();

    $this->actingAs($support->user)
        ->post(route('supports.resume', $support))
        ->assertSessionHasErrors(['plan' => 'このクリエイターは活動を終了しました。']);

    $this->actingAs($support->user)
        ->put(route('supports.update', $support), ['plan_id' => $this->other->id])
        ->assertSessionHasErrors(['plan' => 'このクリエイターは活動を終了しました。']);

    expect($support->fresh()->cancel_at_period_end)->toBeTrue()
        ->and($support->fresh()->plan_id)->toBe($this->plan->id)
        ->and($this->gateway->retrieveSubscription((string) $support->stripe_subscription_id)->cancelAtPeriodEnd)->toBeTrue();
});

it('marks closed creators on the supporting page', function () {
    $support = closureSupport($this->plan);
    $this->actingAs($this->creator->user)->post(route('creator.closure.store'));

    $this->actingAs($support->user)->get(route('supporting.index'))
        ->assertInertia(fn (Assert $page) => $page->where('supports.0.creator_closed', true));
});

it('hides a closed creator from the home page and shows the notice on its page', function () {
    $this->actingAs($this->creator->user)->post(route('creator.closure.store'));
    $open = Creator::factory()->create();

    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
        ->has('creators', 1)
        ->where('creators.0.slug', $open->slug));

    $this->get(route('creators.show', $this->creator))->assertOk()->assertInertia(fn (Assert $page) => $page->where('closed', true));
    $this->get(route('creators.show', $open))->assertInertia(fn (Assert $page) => $page->where('closed', false));
});

it('blocks new checkout for a closed creator', function () {
    $this->creator->forceFill(['closed_at' => now()])->save();
    $fan = User::factory()->create();

    $this->actingAs($fan)
        ->post(route('supports.store', [$this->creator, $this->plan]))
        ->assertSessionHasErrors(['plan' => 'このクリエイターは活動を終了しました。']);
});

it('reopens without reopening the plans', function () {
    $this->actingAs($this->creator->user)->post(route('creator.closure.store'));

    $this->actingAs($this->creator->user)
        ->delete(route('creator.closure.destroy'))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '活動を再開しました。プランの受付を再開してください。']);

    expect($this->creator->fresh()->isClosed())->toBeFalse()
        ->and($this->plan->fresh()->is_accepting)->toBeFalse();
});

it('shows the closure state on the profile page', function () {
    $this->actingAs($this->creator->user)->get(route('creator.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('creator.closed', false));

    $this->creator->forceFill(['closed_at' => now()])->save();

    $this->actingAs($this->creator->user->fresh())->get(route('creator.profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('creator.closed', true));
});

it('is only for creators', function () {
    $this->actingAs(User::factory()->create())->post(route('creator.closure.store'))->assertRedirect();
    expect($this->creator->fresh()->isClosed())->toBeFalse();
});
