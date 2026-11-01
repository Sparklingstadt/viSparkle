<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Records a paid subscription invoice once (keyed by the invoice id), after syncing its subscription.
 */
class PaymentRecorder
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $subscriptions,
    ) {}

    /**
     * Unpaid, zero-amount and non-subscription invoices are not recorded.
     *
     * @throws BillingException
     */
    public function record(string $invoiceId): ?Payment
    {
        $invoice = $this->gateway->retrieveInvoice($invoiceId);

        if ($invoice->status !== 'paid' || $invoice->amountPaid <= 0 || $invoice->subscriptionId === null) {
            return null;
        }

        $support = $this->subscriptions->sync($invoice->subscriptionId);

        if ($support === null) {
            Log::warning('billing.payment_unattributed', ['invoice' => $invoice->id, 'subscription' => $invoice->subscriptionId]);

            return null;
        }

        return Payment::firstOrCreate(['stripe_invoice_id' => $invoice->id], [
            'support_id' => $support->id,
            'user_id' => $support->user_id,
            'creator_id' => $support->creator_id,
            'amount' => $invoice->amountPaid,
            'fee_percent' => config('fanbox.billing.platform_fee_percent'),
            'paid_at' => $invoice->paidAt ?? now(),
        ]);
    }
}
