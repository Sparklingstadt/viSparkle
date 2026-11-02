<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class PaymentCheckoutRequest
{
    /**
     * @param  list<LineItem>  $lineItems
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $customerId,
        public array $lineItems,
        public string $destinationAccountId,
        public int $applicationFeeAmount,
        public array $metadata,
        public string $successUrl,
        public string $cancelUrl,
        public CarbonImmutable $expiresAt,
        public string $idempotencyKey,
    ) {}
}
