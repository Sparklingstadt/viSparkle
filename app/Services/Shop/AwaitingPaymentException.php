<?php

namespace App\Services\Shop;

use App\Models\Order;
use RuntimeException;

/**
 * A checkout was refused because one of the buyer's orders of that creator already has a delayed payment being settled
 * (§6.2 ①-3); the buyer is led to that order.
 */
class AwaitingPaymentException extends RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct('お支払い待ちの注文があります');
    }
}
