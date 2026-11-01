<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\PaymentGateway;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use App\Services\Legal\LegalDocuments;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Starts a support through a Checkout session and turns the returning session into a support.
 * The support row itself is only ever written by SubscriptionSync.
 */
class CheckoutService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
        private readonly Repository $cache,
    ) {}

    /**
     * Create a Checkout session for the plan and return its URL.
     *
     * @throws ValidationException
     * @throws BillingException
     */
    public function begin(User $user, Plan $plan): string
    {
        $this->assertCanBegin($user, $plan);

        $creator = $plan->creator;

        $customerId = $this->customerId($user);
        $price = $this->gateway->ensurePrice($plan);

        // Only one open session per user and creator, so a second tab cannot create a second subscription.
        $key = self::cacheKey($user->id, $creator->id);
        $previous = $this->cache->get($key);

        if (is_string($previous)) {
            try {
                $this->gateway->expireCheckoutSession($previous);
            } catch (BillingException) {
                // Already gone or unreachable; the new session is what matters.
            }
        }

        $minutes = (int) config('fanbox.billing.checkout_expires_minutes');

        $session = $this->gateway->createCheckoutSession(new CheckoutRequest(
            customerId: $customerId,
            priceId: $price->priceId,
            destinationAccountId: (string) $creator->stripe_account_id,
            feePercent: (int) config('fanbox.billing.platform_fee_percent'),
            metadata: [
                // Which environment created it: test-mode accounts are shared, so others' subscriptions reach our webhooks too.
                'app' => (string) config('fanbox.billing.idempotency_prefix'),
                'user_id' => (string) $user->id,
                'creator_id' => (string) $creator->id,
                'plan_id' => (string) $plan->id,
            ],
            successUrl: route('supports.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: route('creators.show', $creator),
            // Stripe requires at least 30 minutes by its own clock, so give it a minute of slack.
            expiresAt: now()->addMinutes($minutes + 1),
        ));

        $this->cache->put($key, $session->id, now()->addMinutes($minutes));

        return $session->url;
    }

    /**
     * Check the support can start; also run when the confirmation page is shown.
     *
     * @throws ValidationException
     */
    public function assertCanBegin(User $user, Plan $plan): void
    {
        // Live payments (sk_live_ or restricted rk_live_ keys) need the 特商法 details published first; test keys are never blocked.
        if (app()->isProduction()
            && str_contains((string) config('fanbox.billing.stripe.secret'), '_live_')
            && ! LegalDocuments::ready()) {
            Log::error('legal.not_ready');

            throw ValidationException::withMessages(['plan' => '現在お申し込みを受け付けていません。']);
        }

        $creator = $plan->creator;

        if ($creator->user_id === $user->id) {
            throw ValidationException::withMessages(['plan' => '自分自身を支援することはできません。']);
        }

        if ($creator->isClosed()) {
            throw ValidationException::withMessages(['plan' => 'このクリエイターは活動を終了しました。']);
        }

        if (! $creator->canAcceptSupports()) {
            throw ValidationException::withMessages(['plan' => 'このクリエイターは現在支援を受け付けていません。']);
        }

        if (! $plan->is_accepting) {
            throw ValidationException::withMessages(['plan' => 'このプランは現在受付を停止しています。']);
        }

        if (Support::active()->where('user_id', $user->id)->where('creator_id', $creator->id)->exists()) {
            throw ValidationException::withMessages(['plan' => 'すでに支援中です。プランの変更は「このプランに変更」から行ってください。']);
        }
    }

    /**
     * Retrieve the session the user came back from; 404 when it is not theirs.
     *
     * @throws BillingException
     */
    public function ownSession(User $user, string $sessionId): CheckoutSessionData
    {
        $session = $this->gateway->retrieveCheckoutSession($sessionId);

        abort_unless(($session->metadata['user_id'] ?? null) === (string) $user->id, 404);

        return $session;
    }

    /**
     * Sync the subscription of a returning session, so access does not wait for the webhook.
     *
     * @throws BillingException
     */
    public function complete(User $user, string $sessionId): ?Support
    {
        return $this->finish($this->ownSession($user, $sessionId));
    }

    /**
     * Like complete(), for a session already fetched with ownSession().
     *
     * @throws BillingException
     */
    public function finish(CheckoutSessionData $session): ?Support
    {
        if ($session->status !== 'complete' || $session->subscriptionId === null) {
            return null;
        }

        $support = $this->sync->sync($session->subscriptionId);

        $userId = $session->metadata['user_id'] ?? null;
        $creatorId = $session->metadata['creator_id'] ?? null;

        if ($userId !== null && $creatorId !== null) {
            $this->cache->forget(self::cacheKey((int) $userId, (int) $creatorId));
        }

        return $support;
    }

    private static function cacheKey(int $userId, int $creatorId): string
    {
        return "checkout:{$userId}:{$creatorId}";
    }

    /**
     * The user's Stripe customer id, created on first use. The row lock is held only for this short transaction.
     */
    private function customerId(User $user): string
    {
        if ($user->stripe_customer_id !== null) {
            return $user->stripe_customer_id;
        }

        $customerId = DB::transaction(function () use ($user): string {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->stripe_customer_id !== null) {
                return $locked->stripe_customer_id;
            }

            $id = $this->gateway->createCustomer($locked);
            $locked->forceFill(['stripe_customer_id' => $id])->save();

            return $id;
        });

        $user->forceFill(['stripe_customer_id' => $customerId])->syncOriginalAttribute('stripe_customer_id');

        return $customerId;
    }
}
