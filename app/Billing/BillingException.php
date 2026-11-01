<?php

namespace App\Billing;

use RuntimeException;

/**
 * Any failure talking to the payment gateway.
 */
class BillingException extends RuntimeException {}
