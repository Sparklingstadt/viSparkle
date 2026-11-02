<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /** The buyer sees their own orders. */
    public function view(User $user, Order $order): bool
    {
        return $order->user_id !== null && $order->user_id === $user->id;
    }

    /** The order's creator ships and refunds it. */
    public function manage(User $user, Order $order): bool
    {
        $creatorId = $user->creator?->id;

        return $creatorId !== null && $order->creator_id === $creatorId;
    }
}
