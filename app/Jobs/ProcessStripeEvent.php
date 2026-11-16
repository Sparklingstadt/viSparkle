<?php

namespace App\Jobs;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Billing\Status\StripeEventType;
use App\Models\StripeEvent;
use App\Services\Billing\AccountSync;
use App\Services\Billing\PaymentRecorder;
use App\Services\Billing\Refunds;
use App\Services\Billing\SubscriptionSync;
use App\Services\Shop\OrderSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies one received Stripe event. Every handler re-reads the current state from Stripe, so retries are safe;
 * `processed_at` is set only after the handler succeeds, and stays null while the queue is still retrying.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public readonly string $eventId,
        public readonly string $type,
        public readonly ?string $objectId,
        public readonly ?string $subscriptionId,
        public readonly ?string $accountId,
        public readonly ?string $chargeId = null,
    ) {}

    /**
     * @throws BillingException
     */
    public function handle(SubscriptionSync $subscriptions, PaymentRecorder $payments, AccountSync $accounts, Refunds $refunds, PaymentGateway $gateway, OrderSync $orders): void
    {
        // An event type the app doesn't handle (tryFrom gives null) is only marked processed.
        match (StripeEventType::tryFrom($this->type)) {
            StripeEventType::CheckoutSessionCompleted => $this->subscriptionId !== null
                ? $subscriptions->sync($this->subscriptionId)
                : ($this->objectId === null ? null : $orders->syncSession($this->objectId)),
            StripeEventType::CheckoutSessionExpired,
            StripeEventType::CheckoutSessionAsyncPaymentSucceeded => $this->objectId === null ? null : $orders->syncSession($this->objectId),
            StripeEventType::CheckoutSessionAsyncPaymentFailed => $this->objectId === null ? null : $orders->syncAsyncFailure($this->objectId),
            StripeEventType::CustomerSubscriptionCreated,
            StripeEventType::CustomerSubscriptionUpdated,
            StripeEventType::CustomerSubscriptionDeleted => $this->objectId === null ? null : $subscriptions->sync($this->objectId),
            StripeEventType::InvoicePaid => $this->objectId === null ? null : $payments->record($this->objectId),
            StripeEventType::InvoicePaymentFailed => $this->syncInvoiceSubscription($subscriptions, $gateway),
            StripeEventType::ChargeRefunded,
            StripeEventType::ChargeDisputeCreated => $this->chargeId === null ? null : $refunds->syncCharge($this->chargeId),
            StripeEventType::AccountUpdated => $this->objectId === null ? null : $accounts->sync($this->objectId),
            StripeEventType::AccountApplicationDeauthorized => $this->accountId === null ? null : $accounts->deauthorize($this->accountId),
            default => null,
        };

        StripeEvent::query()->whereKey($this->eventId)->update(['processed_at' => now()]);
    }

    /**
     * @throws BillingException
     */
    private function syncInvoiceSubscription(SubscriptionSync $subscriptions, PaymentGateway $gateway): void
    {
        if ($this->objectId === null) {
            return;
        }

        $subscriptionId = $gateway->retrieveInvoice($this->objectId)->subscriptionId;

        if ($subscriptionId !== null) {
            $subscriptions->sync($subscriptionId);
        }
    }
}
