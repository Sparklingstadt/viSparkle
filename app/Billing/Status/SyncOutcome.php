<?php

namespace App\Billing\Status;

/** What SubscriptionSync::apply() last did with a subscription. */
enum SyncOutcome: string
{
    /** The support was created or updated. */
    case Synced = 'synced';

    /** A charging subscription with no support to attach to: cancelled and refunded. */
    case Orphan = 'orphan';

    /** A second subscription for a support that already has one: cancelled. */
    case Duplicate = 'duplicate';

    /** Not one of ours: ignored. */
    case Foreign = 'foreign';
}
