<?php

namespace App\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class InvoiceData
{
    public function __construct(
        public string $id,
        public ?string $subscriptionId,
        public string $customerId,
        public string $status,
        public int $amountPaid,
        public ?CarbonImmutable $paidAt,
    ) {}
}
