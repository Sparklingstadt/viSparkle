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
    /** Events about the platform's own objects (checkout, subscriptions, invoices). */
    public function platform(Request $request): Response
    {
        return $this->receive($request, 'webhook_secret');
    }

    /** Events about connected accounts. */
    public function connect(Request $request): Response
    {
        return $this->receive($request, 'connect_webhook_secret');
    }

    private function receive(Request $request, string $secretKey): Response
    {
        $secret = config("fanbox.billing.stripe.{$secretKey}");

        if (! is_string($secret) || $secret === '') {
            Log::error('billing.webhook_secret_missing', ['key' => $secretKey]);

            return response('', 500);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature', ''), $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('', 400);
        }

        $payload = $event->toArray();
        $now = now();

        // A replayed event id inserts nothing: acknowledge it without processing it again.
        $inserted = StripeEvent::query()->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->type,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return response('', 200);
        }

        $object = $payload['data']['object'] ?? [];
        $objectId = $object['id'] ?? null;
        $subscriptionId = $event->type === 'checkout.session.completed' ? ($object['subscription'] ?? null) : null;
        $accountId = $payload['account'] ?? null;

        ProcessStripeEvent::dispatch(
            $event->id,
            $event->type,
            is_string($objectId) ? $objectId : null,
            is_string($subscriptionId) ? $subscriptionId : null,
            is_string($accountId) ? $accountId : null,
        );

        return response('', 200);
    }
}
