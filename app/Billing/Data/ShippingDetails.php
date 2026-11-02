<?php

namespace App\Billing\Data;

final readonly class ShippingDetails
{
    /**
     * @param  string  $address  Prefecture, city, street and building joined by half-width spaces
     */
    public function __construct(
        public string $name,
        public string $postalCode,
        public string $address,
        public ?string $phone,
    ) {}
}
