<?php

namespace App\Billing;

/**
 * A charge was attempted and declined (e.g. the proration invoice of an upgrade).
 */
class PaymentFailedException extends BillingException {}
