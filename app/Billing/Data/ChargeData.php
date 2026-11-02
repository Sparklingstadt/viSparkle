<?php

namespace App\Billing\Data;

final readonly class ChargeData
{
    public function __construct(
        public string $id,
        public ?string $invoiceId,
        public int $amountRefunded,
        public bool $disputed,
        public ?string $paymentIntentId = null,
    ) {}
}
