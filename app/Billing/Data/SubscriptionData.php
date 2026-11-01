<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class SubscriptionData
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $customerId,
        public string $status,
        public ?string $itemId,
        public ?string $priceId,
        public ?int $unitAmount,
        public ?CarbonImmutable $currentPeriodEnd,
        public bool $cancelAtPeriodEnd,
        public array $metadata,
        public ?string $latestInvoiceId = null,
    ) {}
}
