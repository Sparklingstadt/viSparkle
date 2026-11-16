<?php

use App\Billing\BillingException;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\PaymentRecorder;
use App\Services\Billing\Refunds;

beforeEach(function () {
    $this->gateway = fakeBilling();
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
