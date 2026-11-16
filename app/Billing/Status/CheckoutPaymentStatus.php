<?php

namespace App\Billing\Status;

/** Stripe Checkout Session `payment_status` (payment mode). */
enum CheckoutPaymentStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case NoPaymentRequired = 'no_payment_required';
}
