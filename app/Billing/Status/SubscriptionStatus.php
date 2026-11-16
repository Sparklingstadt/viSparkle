<?php

namespace App\Billing\Status;

use App\Enums\SupportStatus;

/**
 * Stripe Subscription `status`. SubscriptionData keeps the raw string (it is stored as `supports.stripe_status`, and
 * Stripe may add statuses); read it through tryFrom().
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Paused = 'paused';

    /** The subscription can no longer charge. */
    public function isEnded(): bool
    {
        return in_array($this, [self::Canceled, self::IncompleteExpired], true);
    }

    /**
     * What the support becomes, or null when the status never creates or changes one (`incomplete`).
     */
    public function supportStatus(): ?SupportStatus
    {
        return match ($this) {
            self::Active, self::Trialing, self::PastDue => SupportStatus::Active,
            self::Canceled, self::Unpaid, self::IncompleteExpired, self::Paused => SupportStatus::Ended,
            self::Incomplete => null,
        };
    }
}
