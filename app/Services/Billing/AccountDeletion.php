<?php

namespace App\Services\Billing;

use App\Billing\BillingException;
use App\Billing\PaymentGateway;
use App\Models\Post;
use App\Models\Product;
use App\Models\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an account after cancelling its billing supports at once, so no subscription keeps charging a deleted user.
 */
class AccountDeletion
{
    /** Stripe statuses of a subscription that can no longer charge. */
    private const ENDED = ['canceled', 'incomplete_expired'];

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
    ) {}

    /**
     * Any cancellation that fails aborts before the user is deleted (supports cancelled so far stay cancelled).
     *
     * @throws BillingException
     */
    public function delete(User $user): void
    {
        $supports = Support::active()->where('user_id', $user->id)->whereNotNull('stripe_subscription_id')->orderBy('id')->get();

        foreach ($supports as $support) {
            $this->cancel((string) $support->stripe_subscription_id);
        }

        DB::transaction(function () use ($user) {
            // The likes go with the user by cascade, which wouldn't touch the counts.
            Post::whereIn('id', $user->postLikes()->select('post_id'))->toBase()->decrement('like_count');
            Product::withTrashed()->whereIn('id', $user->productLikes()->select('product_id'))->toBase()->decrement('like_count');
            $user->delete();
        });
    }

    /**
     * @throws BillingException
     */
    private function cancel(string $subscriptionId): void
    {
        try {
            $this->gateway->cancelSubscriptionNow($subscriptionId);
        } catch (BillingException $e) {
            // Stripe refuses to cancel a subscription that already ended (e.g. a retry after a failed sync): carry on if so.
            if (! in_array($this->gateway->retrieveSubscription($subscriptionId)->status, self::ENDED, true)) {
                throw $e;
            }
        }

        $this->sync->sync($subscriptionId);
    }
}
