<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Mirroring refunds and disputes that happened at Stripe onto the payment rows.
 */
class Refunds
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Mirror the charge's refunds and dispute onto its payment. Returns null when the charge is not for a recorded payment.
     *
     * @throws BillingException
     */
    public function syncCharge(string $chargeId): ?Payment
    {
        $charge = $this->gateway->retrieveCharge($chargeId);

        $payment = $charge->invoiceId === null ? null : Payment::query()->where('stripe_invoice_id', $charge->invoiceId)->first();

        if ($payment === null) {
            Log::warning('billing.charge_unattributed', ['charge' => $chargeId, 'invoice' => $charge->invoiceId]);

            return null;
        }

        $refunded = min($charge->amountRefunded, $payment->amount);

        $payment->forceFill([
            'refunded_amount' => $refunded,
            'refunded_at' => $refunded > 0 ? ($payment->refunded_at ?? now()) : $payment->refunded_at,
            'disputed_at' => $charge->disputed ? ($payment->disputed_at ?? now()) : $payment->disputed_at,
        ])->save();

        return $payment;
    }
}
