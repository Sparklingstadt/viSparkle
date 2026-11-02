<?php

namespace App\Billing\Data;

final readonly class LineItem
{
    public function __construct(
        public string $name,
        public int $unitAmount,
        public int $quantity,
    ) {}
}
