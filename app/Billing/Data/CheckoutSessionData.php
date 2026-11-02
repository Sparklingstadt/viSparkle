<?php

namespace App\Billing\Data;

final readonly class CheckoutSessionData
{
    /**
     * @param  string  $status  open|complete|expired
     * @param  array<string, string>  $metadata
     * @param  string|null  $paymentStatus  paid|unpaid|no_payment_required (payment mode only)
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $status,
        public ?string $subscriptionId,
        public ?string $customerId,
        public array $metadata,
        public ?string $paymentStatus = null,
        public ?string $paymentIntentId = null,
        public ?ShippingDetails $shipping = null,
    ) {}
}
