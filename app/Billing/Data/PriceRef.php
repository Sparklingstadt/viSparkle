<?php

namespace App\Billing\Data;

final readonly class PriceRef
{
    public function __construct(
        public string $productId,
        public string $priceId,
    ) {}
}
