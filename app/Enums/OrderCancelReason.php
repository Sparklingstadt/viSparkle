<?php

namespace App\Enums;

enum OrderCancelReason: string
{
    case Expired = 'expired';
    case Abandoned = 'abandoned';
    case PaymentFailed = 'payment_failed';
    case CheckoutError = 'checkout_error';
    case Creator = 'creator';
    case Admin = 'admin';
}
