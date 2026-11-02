<?php

namespace App\Events\Shop;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order's payment completed (pending → paid).
 * Dispatched only once the surrounding transaction commits, so listeners never see a rolled-back change.
 */
class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
