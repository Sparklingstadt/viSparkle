<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order was refunded in full (→ refunded).
 * Dispatched only once the surrounding transaction commits, so listeners never see a rolled-back change.
 */
class OrderRefunded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
