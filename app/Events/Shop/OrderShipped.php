<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The creator shipped an order (paid → shipped).
 * Dispatched only once the surrounding transaction commits, so listeners never see a rolled-back change.
 */
class OrderShipped implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
