<?php

namespace App\Services;

use App\Enums\SupportStatus;
use App\Models\Support;

/**
 * Ends supports. Starting one goes through CheckoutService and SubscriptionSync.
 */
class SupportService
{
    public function end(Support $support): void
    {
        if ($support->status === SupportStatus::Ended) {
            return;
        }

        $support->update(['status' => SupportStatus::Ended, 'ended_at' => now()]);
    }
}
