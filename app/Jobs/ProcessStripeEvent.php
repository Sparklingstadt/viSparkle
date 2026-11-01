<?php

namespace App\Jobs;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Models\StripeEvent;
use App\Services\Billing\AccountSync;
use App\Services\Billing\PaymentRecorder;
use App\Services\Billing\SubscriptionSync;
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
    ) {}

    /**
     * @throws BillingException
     */
    public function handle(SubscriptionSync $subscriptions, PaymentRecorder $payments, AccountSync $accounts, PaymentGateway $gateway): void
    {
        match ($this->type) {
            'checkout.session.completed' => $this->subscriptionId === null ? null : $subscriptions->sync($this->subscriptionId),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->objectId === null ? null : $subscriptions->sync($this->objectId),
            'invoice.paid' => $this->objectId === null ? null : $payments->record($this->objectId),
            'invoice.payment_failed' => $this->syncInvoiceSubscription($subscriptions, $gateway),
            'account.updated' => $this->objectId === null ? null : $accounts->sync($this->objectId),
            'account.application.deauthorized' => $this->accountId === null ? null : $accounts->deauthorize($this->accountId),
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
