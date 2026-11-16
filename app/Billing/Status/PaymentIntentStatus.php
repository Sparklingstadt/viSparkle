<?php

namespace App\Billing\Status;

/** Stripe PaymentIntent `status`. */
enum PaymentIntentStatus: string
{
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresConfirmation = 'requires_confirmation';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case RequiresCapture = 'requires_capture';
    case Canceled = 'canceled';
    case Succeeded = 'succeeded';
}
