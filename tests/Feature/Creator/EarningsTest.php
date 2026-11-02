<?php

use App\Models\Creator;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->gateway = fakeBilling();
});

function paymentFor(Creator $creator, int $amount, Carbon|string $paidAt, ?int $feePercent = null): Payment
{
    // Timestamps are stored in the app timezone, as the app itself writes them.
    $paidAt = Carbon::parse($paidAt)->setTimezone(config('app.timezone'));
    $support = Support::factory()->forPlan(Plan::factory()->for($creator)->create())->billing()->create();

    return Payment::factory()->forCreator($creator)->create([
        'support_id' => $support->id,
        'amount' => $amount,
        'fee_percent' => $feePercent ?? 10,
        'paid_at' => $paidAt,
    ]);
}

it('starts onboarding and stores the account', function () {
    $creator = Creator::factory()->create();

    $response = $this->actingAs($creator->user)->post(route('creator.earnings.onboarding'));

    $response->assertRedirect(route('creator.earnings.onboarding.return'));
    $accountId = $creator->fresh()->stripe_account_id;
    expect($accountId)->toStartWith('acct_');

    $this->actingAs($creator->user)->post(route('creator.earnings.onboarding'))->assertRedirect(route('creator.earnings.onboarding.return'));
    expect($creator->fresh()->stripe_account_id)->toBe($accountId);
});

it('marks the creator ready after returning from onboarding', function () {
    $creator = Creator::factory()->create();
    $this->actingAs($creator->user)->post(route('creator.earnings.onboarding'));

    $this->actingAs($creator->user)->get(route('creator.earnings.onboarding.return'))
        ->assertRedirect(route('creator.earnings.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '口座の登録が完了しました。']);

    $creator->refresh();
    expect($creator->payouts_enabled)->toBeTrue()->and($creator->canAcceptSupports())->toBeTrue();
});

it('tells the creator when onboarding is not finished yet', function () {
    $creator = Creator::factory()->create();
    $creator->forceFill(['stripe_account_id' => $this->gateway->createConnectAccount($creator)])->save();

    $this->actingAs($creator->user)->get(route('creator.earnings.onboarding.return'))
        ->assertRedirect(route('creator.earnings.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => '登録はまだ完了していません。続きから再開できます。']);
});

it('redirects the return url to the index when there is no account', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)->get(route('creator.earnings.onboarding.return'))
        ->assertRedirect(route('creator.earnings.index'));
});

it('reports the account status', function () {
    $none = Creator::factory()->create();
    $incomplete = Creator::factory()->create();
    $incomplete->forceFill(['stripe_account_id' => $this->gateway->createConnectAccount($incomplete)])->save();
    $ready = Creator::factory()->payable()->create();

    foreach ([[$none, 'none'], [$incomplete, 'incomplete'], [$ready, 'ready']] as [$creator, $status]) {
        $this->actingAs($creator->user)->get(route('creator.earnings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('creator/earnings')
                ->where('account.status', $status)
                ->where('feePercent', 10));
    }
});

it('shows twelve months of earnings', function () {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Tokyo'));
    $creator = Creator::factory()->payable()->create();
    paymentFor($creator, 1000, now());
    paymentFor($creator, 500, now()->subDays(3));
    paymentFor($creator, 999, now()->subMonthsNoOverflow(13));
    paymentFor($creator, 700, now()->subMonthsNoOverflow(11)->startOfMonth());
    paymentFor(Creator::factory()->create(), 5000, now());

    $this->actingAs($creator->user)->get(route('creator.earnings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('months', 12)
            ->where('months.0', ['month' => '2026-11', 'gross' => 1500, 'fee' => 150, 'net' => 1350])
            ->where('months.1', ['month' => '2026-10', 'gross' => 0, 'fee' => 0, 'net' => 0])
            ->where('months.11', ['month' => '2025-12', 'gross' => 700, 'fee' => 70, 'net' => 630]));
});

it('buckets payments by Tokyo calendar month and sums per-payment fees', function () {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Tokyo'));
    $creator = Creator::factory()->payable()->create();
    // 2026-10-31 15:30 UTC is 2026-11-01 00:30 in Tokyo.
    paymentFor($creator, 333, Carbon::parse('2026-10-31 15:30:00', 'UTC'), 10);
    paymentFor($creator, 333, Carbon::parse('2026-10-31 23:59:59', 'Asia/Tokyo'), 15);
    // 2025-11-30 20:00 UTC is 2025-12-01 05:00 in Tokyo, the oldest month of the window.
    paymentFor($creator, 100, Carbon::parse('2025-11-30 20:00:00', 'UTC'));
    // 2025-11-30 14:59 UTC is still 2025-11-30 23:59 in Tokyo, outside the window.
    paymentFor($creator, 900, Carbon::parse('2025-11-30 14:59:00', 'UTC'));

    $this->actingAs($creator->user)->get(route('creator.earnings.index'))
        ->assertInertia(fn ($page) => $page
            ->where('months.0', ['month' => '2026-11', 'gross' => 333, 'fee' => 33, 'net' => 300])
            ->where('months.1', ['month' => '2026-10', 'gross' => 333, 'fee' => 49, 'net' => 284])
            ->where('months.11', ['month' => '2025-12', 'gross' => 100, 'fee' => 10, 'net' => 90]));
});

it('counts sales net of refunds and estimates the fee on the net amount', function () {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Tokyo'));
    $creator = Creator::factory()->payable()->create();
    paymentFor($creator, 1000, now())->forceFill(['refunded_amount' => 1000, 'refunded_at' => now()])->save();
    paymentFor($creator, 999, now(), 10)->forceFill(['refunded_amount' => 333, 'refunded_at' => now()])->save();
    paymentFor($creator, 500, now());

    $this->actingAs($creator->user)->get(route('creator.earnings.index'))
        ->assertInertia(fn ($page) => $page
            ->where('months.0', ['month' => '2026-11', 'gross' => 1166, 'fee' => 116, 'net' => 1050]));
});

it('counts active supporters', function () {
    $creator = Creator::factory()->payable()->create();
    $plan = Plan::factory()->for($creator)->create();
    Support::factory()->forPlan($plan)->active()->count(2)->create();
    Support::factory()->forPlan($plan)->ended()->create();
    Support::factory()->forPlan(Plan::factory()->create())->active()->create();

    $this->actingAs($creator->user)->get(route('creator.earnings.index'))
        ->assertInertia(fn ($page) => $page->where('activeSupporters', 2));
});

it('opens the dashboard for a creator with an account', function () {
    $creator = Creator::factory()->payable()->create();
    $this->gateway->setAccount($creator->stripe_account_id, true, true);

    $this->actingAs($creator->user)->post(route('creator.earnings.dashboard'))
        ->assertRedirect(url('/creator/earnings'));
});

it('hides the dashboard link without an account', function () {
    $creator = Creator::factory()->create();

    $this->actingAs($creator->user)->post(route('creator.earnings.dashboard'))->assertNotFound();
});

it('is only for creators', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('creator.earnings.index'))->assertRedirect(route('creator.register'));
    $this->actingAs($user)->post(route('creator.earnings.onboarding'))->assertRedirect(route('creator.register'));
});

it('requires login', function () {
    $this->get(route('creator.earnings.index'))->assertRedirect(route('login'));
});

it('tells the plans page whether supports are accepted', function () {
    $closed = Creator::factory()->create();
    $open = Creator::factory()->payable()->create();

    $this->actingAs($closed->user)->get(route('creator.plans.index'))
        ->assertInertia(fn ($page) => $page->where('acceptsSupports', false));
    $this->actingAs($open->user)->get(route('creator.plans.index'))
        ->assertInertia(fn ($page) => $page->where('acceptsSupports', true));
});

it('shows monthly shop sales net of refunds and fees', function () {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Tokyo'));
    $creator = Creator::factory()->payable()->create();

    // 3000 paid, fee 300.
    Order::factory()->for($creator)->shipped()->create(['subtotal' => 3000, 'total' => 3000, 'fee_percent' => 10, 'paid_at' => now()]);
    // Fully refunded: no fee, nothing left.
    Order::factory()->for($creator)->paid()->create(['subtotal' => 2000, 'total' => 2000, 'fee_percent' => 10, 'paid_at' => now(), 'refunded_amount' => 2000]);
    // Never paid: ignored.
    Order::factory()->for($creator)->create(['subtotal' => 900, 'total' => 900]);
    // Last month, floor(1005 * 10 / 100) = 100.
    Order::factory()->for($creator)->paid()->create(['subtotal' => 1005, 'total' => 1005, 'fee_percent' => 10, 'paid_at' => now()->subMonthNoOverflow()]);

    $this->actingAs($creator->user)->get(route('creator.earnings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('shop_months', 12)
            ->where('shop_months.0', ['month' => '2026-11', 'orders' => 2, 'sales' => 5000, 'refunded' => 2000, 'net' => 2700, 'supports' => 0, 'support_amount' => 0])
            ->where('shop_months.1', ['month' => '2026-10', 'orders' => 1, 'sales' => 1005, 'refunded' => 0, 'net' => 905, 'supports' => 0, 'support_amount' => 0]));
});

it('counts support in shop sales and takes its own fee', function () {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00', 'Asia/Tokyo'));
    $creator = Creator::factory()->payable()->create();

    // No support: fee 300.
    Order::factory()->for($creator)->paid()->create(['subtotal' => 3000, 'total' => 3000, 'fee_percent' => 10, 'paid_at' => now()]);
    // Support 1000 at 5%: fee 300 + 50.
    Order::factory()->for($creator)->paid()->create([
        'subtotal' => 3000, 'support_amount' => 1000, 'total' => 4000,
        'fee_percent' => 10, 'support_fee_percent' => 5, 'paid_at' => now(),
    ]);
    // Fully refunded with support: no fee, nothing left, but the support is still counted.
    Order::factory()->for($creator)->paid()->create([
        'subtotal' => 1000, 'support_amount' => 300, 'total' => 1300,
        'fee_percent' => 10, 'support_fee_percent' => 5, 'paid_at' => now(), 'refunded_amount' => 1300,
    ]);

    $this->actingAs($creator->user)->get(route('creator.earnings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('shop_months.0', [
            'month' => '2026-11', 'orders' => 3, 'sales' => 8300, 'refunded' => 1300,
            'net' => 8300 - 1300 - 300 - 350, 'supports' => 2, 'support_amount' => 1300,
        ]));
});
