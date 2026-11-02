<?php

use App\Enums\SupportStatus;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Product;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentRecorder;
use App\Services\Billing\SubscriptionSync;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->admin = User::factory()->admin()->create();
});

/**
 * A support checked out on the fake gateway, with its first payment recorded.
 */
function suspensionPayment(Plan $plan, User $fan): Payment
{
    $subscription = fakeCheckout(test()->gateway, $plan, $fan);
    $payment = app(PaymentRecorder::class)->record((string) test()->gateway->latestInvoiceId($subscription->id));
    assert($payment instanceof Payment);

    return $payment;
}

it('lists and searches users', function () {
    $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $bob = User::factory()->suspended('スパム')->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    Creator::factory()->for($bob)->create(['slug' => 'bobs-page']);

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/users')->has('users.data', 3));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'alice@']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$alice->id])
            ->where('q', 'alice@'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'bobs-page']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$bob->id])
            ->where('users.data.0.suspended', true)
            ->where('users.data.0.suspended_reason', 'スパム')
            ->where('users.data.0.creator_slug', 'bobs-page'));

    $this->actingAs($this->admin)->get(route('admin.users.index', ['q' => 'Bo']))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

it('paginates users by 50', function () {
    User::factory()->count(50)->create();

    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 50)->where('users.next_page_url', fn ($url) => $url !== null));
});

it('is admin only', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertNotFound();
    $this->actingAs($user)->patch(route('admin.users.update', User::factory()->create()), ['suspended' => true, 'reason' => 'x'])->assertNotFound();
});

it('requires a reason to suspend', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)->patch(route('admin.users.update', $user), ['suspended' => true, 'reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($user->fresh()->isSuspended())->toBeFalse();
});

it('does not let admins suspend themselves', function () {
    $this->actingAs($this->admin)->patchJson(route('admin.users.update', $this->admin), ['suspended' => true, 'reason' => 'x'])
        ->assertStatus(422);

    expect($this->admin->fresh()->isSuspended())->toBeFalse()
        ->and(AdminAction::count())->toBe(0);
});

it('suspends a fan: blocks login and cancels their supports without refunds', function () {
    $fan = User::factory()->create();
    $plan = Plan::factory()->for(Creator::factory()->payable())->price(800)->create();
    $payment = suspensionPayment($plan, $fan);

    $this->actingAs($this->admin)->patch(route('admin.users.update', $fan), ['suspended' => true, 'reason' => '迷惑行為'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '利用停止にしました。（解約 1件・返金 0件・失敗 0件）']);

    $fan->refresh();
    expect($fan->isSuspended())->toBeTrue()
        ->and($fan->suspended_reason)->toBe('迷惑行為')
        ->and($this->gateway->cancelledNow())->toBe([$payment->support->stripe_subscription_id])
        ->and(Support::sole()->status)->toBe(SupportStatus::Ended)
        ->and($payment->fresh()->refunded_amount)->toBe(0)
        ->and(AdminAction::query()->where('action', 'user.suspended')->sole()->note)->toBe('迷惑行為')
        ->and(AdminAction::query()->where('action', 'user.suspension_billing')->sole())
        ->target_id->toBe($fan->id)->note->toBe('解約 1件・返金 0件・失敗 0件');

    auth()->logout();
    $this->post(route('login.store'), ['email' => $fan->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'このアカウントは利用停止中です。']);
    $this->assertGuest();
});

it('still rejects a wrong password for a suspended user as a failed login', function () {
    $fan = User::factory()->suspended()->create();

    $this->post(route('login.store'), ['email' => $fan->email, 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();
});

it('logs out a live session of a suspended user', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('feed'))->assertOk();

    $user->forceFill(['suspended_at' => now(), 'suspended_reason' => 'x'])->save();

    $this->get(route('feed'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'このアカウントは利用停止中です。']);
    $this->assertGuest();
});

it('suspends a creator: cancels and refunds every supporter and hides their pages', function () {
    $creator = Creator::factory()->payable()->create();
    $plan = Plan::factory()->for($creator)->price(800)->create();
    $first = suspensionPayment($plan, User::factory()->create());
    $second = suspensionPayment($plan, User::factory()->create());
    // Already refunded: its support is cancelled but nothing more is refunded.
    $refunded = suspensionPayment($plan, User::factory()->create());
    $refunded->forceFill(['refunded_amount' => 800, 'refunded_at' => now()])->save();
    // A support whose payment is not recorded yet.
    app(SubscriptionSync::class)->apply(fakeCheckout($this->gateway, $plan, User::factory()->create()));
    $post = Post::factory()->for($creator)->published()->create();
    Product::factory()->for($creator)->create();

    $this->actingAs($this->admin)->patch(route('admin.users.update', $creator->user), ['suspended' => true, 'reason' => '詐欺'])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '利用停止にしました。（解約 4件・返金 2件・失敗 0件）']);

    expect($this->gateway->cancelledNow())->toHaveCount(4)
        ->and(Support::query()->where('status', SupportStatus::Active)->count())->toBe(0)
        ->and($first->fresh()->refunded_amount)->toBe(800)
        ->and($second->fresh()->refunded_amount)->toBe(800)
        ->and(AdminAction::query()->where('action', 'payment.refunded')->pluck('note')->all())->toBe(['利用停止による返金', '利用停止による返金'])
        ->and(AdminAction::query()->where('action', 'user.suspended')->count())->toBe(1)
        ->and(AdminAction::query()->where('action', 'user.suspension_billing')->sole())
        ->target_id->toBe($creator->user->id)->note->toBe('解約 4件・返金 2件・失敗 0件')
        ->and($plan->fresh()->is_accepting)->toBeFalse();

    auth()->logout();
    $this->get(route('creators.show', $creator))->assertNotFound();
    $this->get(route('posts.show', [$creator, $post]))->assertNotFound();
    $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->has('posts', 0)->has('products', 0));

    expect(fn () => app(CheckoutService::class)->assertCanBegin(User::factory()->create(), $plan->fresh()))
        ->toThrow(ValidationException::class);
});

it('counts failures and carries on', function () {
    $creator = Creator::factory()->payable()->create();
    $plan = Plan::factory()->for($creator)->price(800)->create();
    $broken = suspensionPayment($plan, User::factory()->create());
    $ok = suspensionPayment($plan, User::factory()->create());
    $this->gateway->refundInvoice($broken->stripe_invoice_id); // the fake now refuses another refund

    $this->actingAs($this->admin)->patch(route('admin.users.update', $creator->user), ['suspended' => true, 'reason' => '詐欺'])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '利用停止にしました。（解約 2件・返金 1件・失敗 1件）']);

    expect($broken->fresh()->refunded_amount)->toBe(0)
        ->and($ok->fresh()->refunded_amount)->toBe(800)
        ->and($creator->user->fresh()->isSuspended())->toBeTrue();
});

it('refunds only the latest payment of each support', function () {
    $creator = Creator::factory()->payable()->create();
    $plan = Plan::factory()->for($creator)->price(800)->create();
    $recorder = app(PaymentRecorder::class);

    // Two months paid: only the second month is refunded.
    $renewed = suspensionPayment($plan, User::factory()->create());
    $renewal = $recorder->record($this->gateway->advancePeriod((string) $renewed->support->stripe_subscription_id)->id);
    // Latest month already refunded: the older month is not refunded instead.
    $refunded = suspensionPayment($plan, User::factory()->create());
    $refundedRenewal = $recorder->record($this->gateway->advancePeriod((string) $refunded->support->stripe_subscription_id)->id);
    $refundedRenewal->forceFill(['refunded_amount' => 800, 'refunded_at' => now()])->save();

    $this->actingAs($this->admin)->patch(route('admin.users.update', $creator->user), ['suspended' => true, 'reason' => '詐欺'])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '利用停止にしました。（解約 2件・返金 1件・失敗 0件）']);

    expect($renewal->fresh()->refunded_amount)->toBe(800)
        ->and($renewed->fresh()->refunded_amount)->toBe(0)
        ->and($refunded->fresh()->refunded_amount)->toBe(0)
        ->and(AdminAction::query()->where('action', 'payment.refunded')->sole()->target_id)->toBe($renewal->id);
});

it('re-runs the billing work for an already-suspended user without changing suspended_at', function () {
    $creator = Creator::factory()->payable()->create();
    $plan = Plan::factory()->for($creator)->price(800)->create();
    $payment = suspensionPayment($plan, User::factory()->create());
    // An earlier suspension whose billing work did not finish.
    $suspendedAt = now()->subDay()->startOfSecond();
    $creator->user->forceFill(['suspended_at' => $suspendedAt, 'suspended_reason' => '詐欺'])->save();

    $this->actingAs($this->admin)->patch(route('admin.users.update', $creator->user), ['suspended' => true, 'reason' => '詐欺'])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '利用停止にしました。（解約 1件・返金 1件・失敗 0件）']);

    expect($creator->user->fresh()->suspended_at->equalTo($suspendedAt))->toBeTrue()
        ->and($this->gateway->cancelledNow())->toBe([$payment->support->stripe_subscription_id])
        ->and($payment->fresh()->refunded_amount)->toBe(800);

    // Nothing is left to do: a further re-run touches nothing and records no billing result.
    $this->actingAs($this->admin)->patch(route('admin.users.update', $creator->user), ['suspended' => true, 'reason' => '詐欺'])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '利用停止にしました。（解約 0件・返金 0件・失敗 0件）']);

    expect($this->gateway->cancelledNow())->toHaveCount(1)
        ->and(AdminAction::query()->where('action', 'user.suspension_billing')->count())->toBe(1)
        ->and($creator->user->fresh()->suspended_at->equalTo($suspendedAt))->toBeTrue();
});

it('unsuspends a user so they can log in again', function () {
    $user = User::factory()->suspended()->create();

    $this->actingAs($this->admin)->patch(route('admin.users.update', $user), ['suspended' => false])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '利用停止を解除しました。']);

    $user->refresh();
    expect($user->suspended_at)->toBeNull()
        ->and($user->suspended_reason)->toBeNull()
        ->and(AdminAction::sole()->action)->toBe('user.unsuspended');

    auth()->logout();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});
