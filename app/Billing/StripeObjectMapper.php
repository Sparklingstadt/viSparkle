<?php

namespace App\Billing;

use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\InvoiceData;
use App\Billing\Data\ShippingDetails;
use App\Billing\Data\SubscriptionData;
use Carbon\CarbonImmutable;
use Stripe\Invoice;
use Stripe\StripeObject;
use Stripe\Subscription;

/**
 * Turns Stripe objects into the gateway's data objects. Pure functions: the differences between Stripe API versions
 * (fields that moved) are absorbed here, so they can be tested without calling Stripe.
 */
final class StripeObjectMapper
{
    /**
     * The period end moved from the subscription to its items in newer API versions; both are read.
     */
    public static function subscription(Subscription $subscription): SubscriptionData
    {
        $data = $subscription->toArray();
        $item = $data['items']['data'][0] ?? null;
        $periodEnd = $item['current_period_end'] ?? $data['current_period_end'] ?? null;

        return new SubscriptionData(
            id: (string) $data['id'],
            customerId: self::idOf($data['customer'] ?? null) ?? '',
            status: (string) ($data['status'] ?? ''),
            itemId: isset($item['id']) ? (string) $item['id'] : null,
            priceId: isset($item['price']['id']) ? (string) $item['price']['id'] : null,
            unitAmount: isset($item['price']['unit_amount']) ? (int) $item['price']['unit_amount'] : null,
            currentPeriodEnd: $periodEnd === null ? null : CarbonImmutable::createFromTimestamp((int) $periodEnd, config('app.timezone')),
            cancelAtPeriodEnd: ($data['cancel_at_period_end'] ?? false) === true,
            metadata: self::stringMap($data['metadata'] ?? []),
            latestInvoiceId: self::idOf($data['latest_invoice'] ?? null),
        );
    }

    /**
     * The subscription moved under parent.subscription_details in newer API versions; the old field is the fallback.
     */
    public static function invoice(Invoice $invoice): InvoiceData
    {
        $data = $invoice->toArray();
        $paidAt = $data['status_transitions']['paid_at'] ?? null;
        $subscription = $data['parent']['subscription_details']['subscription'] ?? $data['subscription'] ?? null;

        return new InvoiceData(
            id: (string) $data['id'],
            subscriptionId: self::idOf($subscription),
            customerId: self::idOf($data['customer'] ?? null) ?? '',
            status: (string) ($data['status'] ?? ''),
            amountPaid: (int) ($data['amount_paid'] ?? 0),
            paidAt: $paidAt === null ? null : CarbonImmutable::createFromTimestamp((int) $paidAt, config('app.timezone')),
        );
    }

    /**
     * @return array{payment_intent: string}|array{charge: string}|null
     */
    public static function paymentTarget(mixed $payment): ?array
    {
        if (! is_array($payment)) {
            return null;
        }

        $paymentIntentId = self::idOf($payment['payment_intent'] ?? null);

        if ($paymentIntentId !== null) {
            return ['payment_intent' => $paymentIntentId];
        }

        $chargeId = self::idOf($payment['charge'] ?? null);

        return $chargeId === null ? null : ['charge' => $chargeId];
    }

    public static function checkoutSession(StripeObject $session): CheckoutSessionData
    {
        $data = $session->toArray();

        return new CheckoutSessionData(
            id: (string) $data['id'],
            url: (string) ($data['url'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            subscriptionId: self::idOf($data['subscription'] ?? null),
            customerId: self::idOf($data['customer'] ?? null),
            metadata: self::stringMap($data['metadata'] ?? []),
            paymentStatus: isset($data['payment_status']) ? (string) $data['payment_status'] : null,
            paymentIntentId: self::idOf($data['payment_intent'] ?? null),
            shipping: self::shipping($data),
        );
    }

    /**
     * Shipping moved to collected_information in newer API versions; the old top-level field is the fallback.
     *
     * @param  array<string, mixed>  $session
     */
    private static function shipping(array $session): ?ShippingDetails
    {
        $shipping = $session['collected_information']['shipping_details'] ?? $session['shipping_details'] ?? null;

        if (! is_array($shipping)) {
            return null;
        }

        $address = is_array($shipping['address'] ?? null) ? $shipping['address'] : [];
        $parts = array_filter(
            array_map(fn (string $key) => trim((string) ($address[$key] ?? '')), ['state', 'city', 'line1', 'line2']),
            fn (string $part) => $part !== '',
        );
        $phone = $session['customer_details']['phone'] ?? null;

        return new ShippingDetails(
            name: (string) ($shipping['name'] ?? ''),
            postalCode: (string) ($address['postal_code'] ?? ''),
            address: implode(' ', $parts),
            phone: is_string($phone) && $phone !== '' ? $phone : null,
        );
    }

    /**
     * Expandable fields come back as an id or as the expanded object.
     */
    public static function idOf(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        return is_array($value) ? array_map(strval(...), $value) : [];
    }
}
