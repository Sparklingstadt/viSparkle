<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class CheckoutRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $customerId,
        public string $priceId,
        public array $metadata,
        public string $successUrl,
        public string $cancelUrl,
        public CarbonImmutable $expiresAt,
    ) {}
}
