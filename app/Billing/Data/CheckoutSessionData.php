<?php

namespace App\Billing\Data;

final readonly class CheckoutSessionData
{
    /**
     * @param  string  $status  open|complete|expired
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $url,
        public string $status,
        public ?string $subscriptionId,
        public ?string $customerId,
        public array $metadata,
    ) {}
}
