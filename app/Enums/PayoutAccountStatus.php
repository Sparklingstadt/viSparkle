<?php

namespace App\Enums;

use App\Models\Creator;

/** Where a creator stands with their Stripe payout account, as the earnings page shows it. */
enum PayoutAccountStatus: string
{
    case None = 'none';
    case Incomplete = 'incomplete';
    case Ready = 'ready';

    public static function of(Creator $creator): self
    {
        if ($creator->stripe_account_id === null) {
            return self::None;
        }

        return $creator->canAcceptSupports() ? self::Ready : self::Incomplete;
    }
}
