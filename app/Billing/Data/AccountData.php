<?php

namespace App\Billing\Data;

final readonly class AccountData
{
    public function __construct(
        public string $id,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
    ) {}
}
