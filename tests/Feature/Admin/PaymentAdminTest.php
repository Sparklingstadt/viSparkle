<?php

use App\Enums\SupportStatus;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->admin = User::factory()->admin()->create();
});

/**
 * A payment recorded through the fake gateway, so it can actually be refunded.
 */
function recordedPayment(Creator $creator, User $fan, int $price = 800): Payment
{
    $plan = Plan::factory()->for($creator)->price($price)->create();
    $subscription = fakeCheckout(test()->gateway, $plan, $fan);
    $payment = app(PaymentRecorder::class)->record((string) test()->gateway->latestInvoiceId($subscription->id));
    assert($payment instanceof Payment);

    return $payment;
}

it('lists payments newest first with refund and dispute state', function () {
    $creator = Creator::factory()->payable()->create();
    $fan = User::factory()->create();
    $old = recordedPayment($creator, $fan);
    $old->forceFill(['paid_at' => now()->subDays(3), 'refunded_amount' => 800, 'refunded_at' => now(), 'disputed_at' => now()])->save();
    $new = recordedPayment(Creator::factory()->payable()->create(), User::factory()->create(), 500);

    $this->actingAs($this->admin)->get(route('admin.payments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/payments')
            ->where('payments.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$new->id, $old->id])
            ->where('payments.data.1.creator', ['name' => $creator->display_name, 'slug' => $creator->slug])
            ->where('payments.data.1.supporter', ['name' => $fan->name, 'email' => $fan->email])
            ->where('payments.data.1.amount', 800)
            ->where('payments.data.1.refunded_amount', 800)
            ->where('payments.data.1.refunded', true)
            ->where('payments.data.1.disputed', true)
            ->where('payments.data.1.support_active', true)
            ->where('payments.data.0.refunded', false)
            ->where('payments.data.0.disputed', false)
            ->where('filters', ['creator' => null, 'user' => null]));
});

it('filters by creator slug and supporter email', function () {
    $creator = Creator::factory()->payable()->create();
    $fan = User::factory()->create();
    $mine = recordedPayment($creator, $fan);
    recordedPayment($creator, User::factory()->create(), 500);
    recordedPayment(Creator::factory()->payable()->create(), $fan);

    $this->actingAs($this->admin)->get(route('admin.payments.index', ['creator' => $creator->slug, 'user' => $fan->email]))
        ->assertInertia(fn ($page) => $page
            ->where('payments.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id])
            ->where('filters', ['creator' => $creator->slug, 'user' => $fan->email]));

    $this->actingAs($this->admin)->get(route('admin.payments.index', ['creator' => $creator->slug]))
        ->assertInertia(fn ($page) => $page->has('payments.data', 2));
});

it('paginates by 50', function () {
    $support = Support::factory()->billing()->create();
    Payment::factory()->for($support)->count(51)->create();

    $this->actingAs($this->admin)->get(route('admin.payments.index'))
        ->assertInertia(fn ($page) => $page->has('payments.data', 50)->where('payments.next_page_url', fn ($url) => $url !== null));
});

it('refunds a payment', function () {
    $payment = recordedPayment(Creator::factory()->payable()->create(), User::factory()->create());

    $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['cancel_support' => false])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '返金しました。']);

    expect($payment->fresh()->refunded_amount)->toBe(800)
        ->and(Support::sole()->status)->toBe(SupportStatus::Active)
        ->and(AdminAction::sole()->action)->toBe('payment.refunded');
});

it('refunds and cancels the support', function () {
    $payment = recordedPayment(Creator::factory()->payable()->create(), User::factory()->create());

    $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['cancel_support' => true])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '返金しました。']);

    expect(Support::sole()->status)->toBe(SupportStatus::Ended)
        ->and($payment->fresh()->refunded_amount)->toBe(800);
});

it('rejects refunding a refunded payment', function () {
    $payment = recordedPayment(Creator::factory()->payable()->create(), User::factory()->create());
    $payment->forceFill(['refunded_amount' => 800, 'refunded_at' => now()])->save();

    $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['cancel_support' => false])
        ->assertSessionHasErrors(['payment' => 'この支払いはすでに返金済みです。']);

    expect(AdminAction::count())->toBe(0);
});

it('shows an error and records the failure when the refund fails', function () {
    $payment = recordedPayment(Creator::factory()->payable()->create(), User::factory()->create());
    $this->gateway->refundInvoice($payment->stripe_invoice_id); // the fake now refuses another refund

    $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['cancel_support' => false])
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '返金できませんでした。時間をおいてお試しください。']);

    expect($payment->fresh()->refunded_amount)->toBe(0)
        ->and(AdminAction::sole()->action)->toBe('payment.refund_failed');
});

it('validates the cancel option', function () {
    $payment = Payment::factory()->create();

    $this->actingAs($this->admin)->post(route('admin.payments.refund', $payment), ['cancel_support' => 'maybe'])
        ->assertSessionHasErrors('cancel_support');
});

it('returns 404 to non-admins', function () {
    $payment = Payment::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.payments.index'))->assertNotFound();
    $this->actingAs($user)->post(route('admin.payments.refund', $payment), ['cancel_support' => false])->assertNotFound();

    expect($payment->fresh()->refunded_amount)->toBe(0);
});

it('shows the kinds of the lines on shop orders', function () {
    $creator = Creator::factory()->payable()->create();
    $digital = Order::factory()->for($creator)->paid()->withItem(Product::factory()->for($creator)->digital()->create())->create();
    $goods = Order::factory()->for($creator)->paid()->withItem(Product::factory()->for($creator)->create())->create();

    $this->actingAs($this->admin)->get(route('admin.payments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('orders.data', fn ($rows) => collect($rows)->firstWhere('id', $digital->id)['has_digital'] === true
                && collect($rows)->firstWhere('id', $goods->id)['has_digital'] === false));
});
