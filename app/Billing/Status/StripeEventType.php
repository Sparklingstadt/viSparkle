<?php

namespace App\Billing\Status;

/** The Stripe webhook events the app handles; any other type is recorded and ignored. */
enum StripeEventType: string
{
    case CheckoutSessionCompleted = 'checkout.session.completed';
    case CheckoutSessionExpired = 'checkout.session.expired';
    case CheckoutSessionAsyncPaymentSucceeded = 'checkout.session.async_payment_succeeded';
    case CheckoutSessionAsyncPaymentFailed = 'checkout.session.async_payment_failed';
    case CustomerSubscriptionCreated = 'customer.subscription.created';
    case CustomerSubscriptionUpdated = 'customer.subscription.updated';
    case CustomerSubscriptionDeleted = 'customer.subscription.deleted';
    case InvoicePaid = 'invoice.paid';
    case InvoicePaymentFailed = 'invoice.payment_failed';
    case ChargeRefunded = 'charge.refunded';
    case ChargeDisputeCreated = 'charge.dispute.created';
    case AccountUpdated = 'account.updated';
    case AccountApplicationDeauthorized = 'account.application.deauthorized';
}
