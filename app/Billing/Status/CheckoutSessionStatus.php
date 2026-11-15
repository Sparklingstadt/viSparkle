<?php

namespace App\Billing\Status;

/** Stripe Checkout Session `status`. CheckoutSessionData keeps the raw string; compare against `->value`. */
enum CheckoutSessionStatus: string
{
    case Open = 'open';
    case Complete = 'complete';
    case Expired = 'expired';
}
