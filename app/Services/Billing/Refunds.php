<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\Data\ChargeData;
use App\Billing\PaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Shop\Orders;
use Illuminate\Support\Facades\Log;

/**
 * Mirroring refunds and disputes that happened at Stripe onto the payment rows.
 */
class Refunds
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Orders $orders,
    ) {}

    /**
     * Mirror the charge's refunds and dispute onto its payment. Returns null when the charge is not for a recorded payment;
     * a charge without an invoice may be a shop order's payment, which is synced onto the order instead.
     *
     * @throws BillingException
     */
    public function syncCharge(string $chargeId): ?Payment
    {
        $charge = $this->gateway->retrieveCharge($chargeId);

        if ($charge->invoiceId === null) {
            $this->syncOrderCharge($chargeId, $charge);

            return null;
        }

        $payment = Payment::query()->where('stripe_invoice_id', $charge->invoiceId)->first();

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

    /**
     * A charge with no invoice: mirror it onto the order of its payment intent, if there is one.
     */
    private function syncOrderCharge(string $chargeId, ChargeData $charge): void
    {
        $order = $charge->paymentIntentId === null ? null : Order::query()->where('stripe_payment_intent_id', $charge->paymentIntentId)->first();

        if ($order === null) {
            Log::warning('billing.charge_unattributed', ['charge' => $chargeId, 'invoice' => null, 'payment_intent' => $charge->paymentIntentId]);

            return;
        }

        $this->orders->syncRefund($order, $charge);
    }
}
