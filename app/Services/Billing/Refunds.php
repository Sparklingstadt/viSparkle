<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\AdminAction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Full refunds of recorded payments, and mirroring refunds and disputes that happened at Stripe onto the payment rows.
 */
class Refunds
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
    ) {}

    /**
     * Refund the payment in full (the creator's transfer and the platform fee come back too). The support stays unless
     * $cancelSupport; then a billing, active support is cancelled now. A failed cancellation is logged and noted, not thrown.
     * $note replaces the default「支援は継続」note of a refund that leaves the support alone (e.g. a suspension's refund).
     *
     * @throws ValidationException when the payment is already refunded
     * @throws BillingException when the refund fails (nothing is recorded)
     */
    public function refund(Payment $payment, User $admin, bool $cancelSupport, ?string $note = null): void
    {
        if ($payment->refunded_amount >= $payment->amount) {
            throw ValidationException::withMessages(['payment' => 'この支払いはすでに返金済みです。']);
        }

        $this->gateway->refundInvoice($payment->stripe_invoice_id);
        $payment->forceFill(['refunded_amount' => $payment->amount, 'refunded_at' => now()])->save();

        $support = $payment->support;
        $note ??= '支援は継続';

        if ($cancelSupport && $support !== null && $support->isBilling() && $support->status === SupportStatus::Active) {
            try {
                $this->gateway->cancelSubscriptionNow((string) $support->stripe_subscription_id);
                $this->sync->sync((string) $support->stripe_subscription_id);
                $note = '支援も解約';
            } catch (BillingException $e) {
                $note = '支援の解約に失敗';
                Log::error('billing.ops_failed', [
                    'operation' => 'refund_cancel_support',
                    'payment_id' => $payment->id,
                    'support_id' => $support->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        AdminAction::record($admin, 'payment.refunded', $payment, $note);
    }

    /**
     * Mirror the charge's refunds and dispute onto its payment. Returns null when the charge is not for a recorded payment.
     *
     * @throws BillingException
     */
    public function syncCharge(string $chargeId): ?Payment
    {
        $charge = $this->gateway->retrieveCharge($chargeId);

        if ($charge->invoiceId === null) {
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
}
