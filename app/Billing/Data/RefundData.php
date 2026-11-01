<?php

namespace App\Billing\Data;

final readonly class RefundData
{
    public function __construct(
        public string $id,
        public int $amount,
    ) {}
}
