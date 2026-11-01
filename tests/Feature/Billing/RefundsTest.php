<?php

use App\Billing\BillingException;
use App\Enums\SupportStatus;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;
use App\Services\Billing\Refunds;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->admin = User::factory()->admin()->create();
    $this->creator = Creator::factory()->payable()->create();
    $this->plan = Plan::factory()->for($this->creator)->price(800)->create();
    $this->fan = User::factory()->create();
});

/**
 * Check out and record the first payment on the fake gateway.
 */
function paidPayment(): Payment
{
    $subscription = fakeCheckout(test()->gateway, test()->plan, test()->fan);
    $payment = app(PaymentRecorder::class)->record((string) test()->gateway->latestInvoiceId($subscription->id));
    assert($payment instanceof Payment);

    return $payment;
}

it('refunds a payment in full and records it', function () {
    $this->travelTo(now()->startOfSecond());
    $payment = paidPayment();

    app(Refunds::class)->refund($payment, $this->admin, false);

    $payment->refresh();
    expect($payment->refunded_amount)->toBe(800)
        ->and($payment->refunded_at->equalTo(now()))->toBeTrue()
        ->and($this->gateway->retrieveCharge($this->gateway->chargeIdForInvoice($payment->stripe_invoice_id))->amountRefunded)->toBe(800)
        ->and(Support::sole()->status)->toBe(SupportStatus::Active)
        ->and($this->gateway->cancelledNow())->toBe([]);

    $action = AdminAction::sole();
    expect($action->admin_id)->toBe($this->admin->id)
        ->and($action->action)->toBe('payment.refunded')
        ->and($action->target_type)->toBe('payment')
        ->and($action->target_id)->toBe($payment->id)
        ->and($action->note)->toBe('支援は継続');
});

it('refuses to refund a payment twice', function () {
    $payment = paidPayment();
    app(Refunds::class)->refund($payment, $this->admin, false);

    try {
        app(Refunds::class)->refund($payment->fresh(), $this->admin, false);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['payment' => ['この支払いはすでに返金済みです。']])
            ->and($e->status)->toBe(422);
    }

    expect(AdminAction::count())->toBe(1);
});

it('also cancels the support when asked', function () {
    $payment = paidPayment();
    $support = Support::sole();

    app(Refunds::class)->refund($payment, $this->admin, true);

    expect($this->gateway->cancelledNow())->toBe([$support->stripe_subscription_id])
        ->and($support->fresh()->status)->toBe(SupportStatus::Ended)
        ->and($payment->fresh()->refunded_amount)->toBe(800)
        ->and(AdminAction::sole()->note)->toBe('支援も解約');
});

it('does not cancel a support that has already ended', function () {
    $payment = paidPayment();
    $support = Support::sole();
    $support->forceFill(['status' => SupportStatus::Ended, 'ended_at' => now()])->save();

    app(Refunds::class)->refund($payment, $this->admin, true);

    expect($this->gateway->cancelledNow())->toBe([])
        ->and($payment->fresh()->refunded_amount)->toBe(800);
});

it('records nothing when the gateway refund fails', function () {
    $payment = paidPayment();
    $this->gateway->refundInvoice($payment->stripe_invoice_id); // refunded elsewhere; the fake now refuses a second refund

    expect(fn () => app(Refunds::class)->refund($payment, $this->admin, false))->toThrow(BillingException::class);

    expect($payment->fresh()->refunded_amount)->toBe(0)
        ->and(AdminAction::count())->toBe(0);
});

it('syncs a refund made on the stripe dashboard', function () {
    $this->travelTo(now()->startOfSecond());
    $payment = paidPayment();
    $chargeId = $this->gateway->chargeIdForInvoice($payment->stripe_invoice_id);
    $this->gateway->refundInvoice($payment->stripe_invoice_id);

    $synced = app(Refunds::class)->syncCharge($chargeId);

    expect($synced?->id)->toBe($payment->id);
    $payment->refresh();
    expect($payment->refunded_amount)->toBe(800)
        ->and($payment->refunded_at->equalTo(now()))->toBeTrue()
        ->and($payment->disputed_at)->toBeNull();

    // A later sync keeps the first refund time.
    $this->travel(1)->hours();
    app(Refunds::class)->syncCharge($chargeId);
    expect($payment->fresh()->refunded_at->equalTo($payment->refunded_at))->toBeTrue();
});

it('records a dispute', function () {
    $this->travelTo(now()->startOfSecond());
    $payment = paidPayment();
    $chargeId = $this->gateway->chargeIdForInvoice($payment->stripe_invoice_id);
    $this->gateway->markDisputed($chargeId);

    app(Refunds::class)->syncCharge($chargeId);

    $payment->refresh();
    expect($payment->disputed_at->equalTo(now()))->toBeTrue()
        ->and($payment->refunded_amount)->toBe(0)
        ->and($payment->refunded_at)->toBeNull();
});

it('ignores a charge without a recorded payment', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $chargeId = $this->gateway->chargeIdForInvoice((string) $this->gateway->latestInvoiceId($subscription->id));

    expect(app(Refunds::class)->syncCharge($chargeId))->toBeNull()
        ->and(Payment::count())->toBe(0);
});

it('marks a fake invoice refunded only once', function () {
    $subscription = fakeCheckout($this->gateway, $this->plan, $this->fan);
    $invoiceId = (string) $this->gateway->latestInvoiceId($subscription->id);

    expect($this->gateway->refundInvoice($invoiceId)->amount)->toBe(800)
        ->and(fn () => $this->gateway->refundInvoice($invoiceId))->toThrow(BillingException::class)
        ->and($this->gateway->retrieveCharge($this->gateway->chargeIdForInvoice($invoiceId))->invoiceId)->toBe($invoiceId);
});
