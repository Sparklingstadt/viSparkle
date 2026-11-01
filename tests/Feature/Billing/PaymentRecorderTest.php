<?php

use App\Billing\Data\InvoiceData;
use App\Billing\PaymentGateway;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;
use Illuminate\Support\Facades\Log;

it('records a paid invoice once', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->price(800)->create();
    $fan = User::factory()->create();
    $subscription = fakeCheckout($gateway, $plan, $fan);
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);
    $recorder = app(PaymentRecorder::class);

    $payment = $recorder->record($invoiceId);
    $again = $recorder->record($invoiceId);

    $support = Support::sole();
    expect(Payment::count())->toBe(1)
        ->and($again->id)->toBe($payment->id)
        ->and($payment->stripe_invoice_id)->toBe($invoiceId)
        ->and($payment->support_id)->toBe($support->id)
        ->and($payment->user_id)->toBe($fan->id)
        ->and($payment->creator_id)->toBe($plan->creator_id)
        ->and($payment->amount)->toBe(800)
        ->and($payment->fee_percent)->toBe(10)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($support->stripe_subscription_id)->toBe($subscription->id);
});

it('uses the configured platform fee', function () {
    config(['fanbox.billing.platform_fee_percent' => 15]);
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());

    $payment = app(PaymentRecorder::class)->record((string) $gateway->latestInvoiceId($subscription->id));

    expect($payment->fee_percent)->toBe(15);
});

it('skips zero-amount invoices', function () {
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->price(0)->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());

    expect(app(PaymentRecorder::class)->record((string) $gateway->latestInvoiceId($subscription->id)))->toBeNull()
        ->and(Payment::count())->toBe(0);
});

it('logs a paid invoice it cannot attribute to a support', function () {
    Log::spy();
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $fan = User::factory()->create();
    $subscription = fakeCheckout($gateway, $plan, $fan, [
        'user_id' => (string) User::factory()->create()->id,
        'creator_id' => (string) $plan->creator_id,
        'plan_id' => (string) $plan->id,
    ]);
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);

    expect(app(PaymentRecorder::class)->record($invoiceId))->toBeNull()
        ->and(Payment::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.payment_unattributed', ['invoice' => $invoiceId, 'subscription' => $subscription->id])->once();
});

it('marks the unattributed payment of an orphan-cancelled subscription', function () {
    Log::spy();
    $gateway = fakeBilling();
    $plan = Plan::factory()->for(Creator::factory()->payable())->create();
    $subscription = fakeCheckout($gateway, $plan, User::factory()->create());
    $plan->creator->forceFill(['closed_at' => now()])->save();
    $invoiceId = (string) $gateway->latestInvoiceId($subscription->id);

    expect(app(PaymentRecorder::class)->record($invoiceId))->toBeNull()
        ->and(Payment::count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('billing.payment_unattributed', ['invoice' => $invoiceId, 'subscription' => $subscription->id, 'orphan' => true])->once();
});

it('skips invoices that are unpaid or not for a subscription', function (InvoiceData $invoice) {
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('retrieveInvoice')->with($invoice->id)->andReturn($invoice);
    $gateway->shouldNotReceive('retrieveSubscription');
    app()->instance(PaymentGateway::class, $gateway);

    expect(app(PaymentRecorder::class)->record($invoice->id))->toBeNull()
        ->and(Payment::count())->toBe(0);
})->with([
    'open' => fn () => new InvoiceData('in_1', 'sub_1', 'cus_1', 'open', 500, null),
    'no subscription' => fn () => new InvoiceData('in_2', null, 'cus_1', 'paid', 500, now()->toImmutable()),
]);
