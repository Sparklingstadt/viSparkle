<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessStripeEvent;
use App\Models\StripeEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    /** Events about our Stripe account's objects (checkout, subscriptions, invoices). */
    public function __invoke(Request $request): Response
    {
        $secret = config('fanbox.billing.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('billing.webhook_secret_missing');

            return response('', 500);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature', ''), $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('', 400);
        }

        $payload = $event->toArray();
        $now = now();

        // A replayed event id inserts nothing. If that earlier delivery was never processed (e.g. enqueueing failed), run it again;
        // the handlers re-read Stripe's state, so processing twice is harmless. A processed event is just acknowledged.
        $inserted = StripeEvent::query()->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->type,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0 && StripeEvent::query()->whereKey($event->id)->whereNotNull('processed_at')->exists()) {
            return response('', 200);
        }

        $object = $payload['data']['object'] ?? [];
        $objectId = $object['id'] ?? null;
        $subscriptionId = $event->type === 'checkout.session.completed' ? ($object['subscription'] ?? null) : null;

        ProcessStripeEvent::dispatch(
            $event->id,
            $event->type,
            is_string($objectId) ? $objectId : null,
            is_string($subscriptionId) ? $subscriptionId : null,
        );

        return response('', 200);
    }
}
