<?php

namespace App\Services;

use App\Billing\BillingException;
use App\Billing\Data\SubscriptionData;
use App\Billing\PaymentFailedException;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Plan;
use App\Models\Support;
use App\Services\Billing\SubscriptionSync;
use Illuminate\Validation\ValidationException;

/**
 * Stops, resumes and changes supports. Starting one goes through CheckoutService; billing state is only
 * ever written back by SubscriptionSync from what Stripe returns.
 */
class SupportService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SubscriptionSync $sync,
    ) {}

    /**
     * Dummy supports end at once; billing ones are scheduled to end with the paid period.
     *
     * @throws ValidationException
     * @throws BillingException
     */
    public function cancel(Support $support): Support
    {
        if ($support->status === SupportStatus::Ended) {
            throw self::refuse('この支援はすでに終了しています。');
        }

        if (! $support->isBilling()) {
            $support->update(['status' => SupportStatus::Ended, 'ended_at' => now()]);

            return $support;
        }

        return $this->apply($support, $this->gateway->setCancelAtPeriodEnd((string) $support->stripe_subscription_id, true));
    }

    /**
     * @throws ValidationException
     * @throws BillingException
     */
    public function resume(Support $support): Support
    {
        if (! $support->isBilling() || $support->status !== SupportStatus::Active || ! $support->cancel_at_period_end) {
            throw self::refuse('再開できる支援がありません。');
        }

        // A closed creator's supports run out with their period; renewing them would charge with no way to stop.
        if ($support->creator->isClosed()) {
            throw self::refuse('このクリエイターは活動を終了しました。');
        }

        return $this->apply($support, $this->gateway->setCancelAtPeriodEnd((string) $support->stripe_subscription_id, false));
    }

    /**
     * Move to another plan of the same creator. An upgrade only takes effect once its proration is paid.
     *
     * @throws ValidationException
     * @throws BillingException
     */
    public function changePlan(Support $support, Plan $plan): Support
    {
        if ($support->status === SupportStatus::Ended) {
            throw self::refuse('この支援はすでに終了しています。');
        }

        if ($support->creator->isClosed()) {
            throw self::refuse('このクリエイターは活動を終了しました。');
        }

        if ($support->plan_id === $plan->id) {
            throw self::refuse('すでにこのプランで支援中です。');
        }

        if (! $support->isBilling()) {
            throw self::refuse('この支援はプラン変更に対応していません。停止してから支援し直してください。');
        }

        if ($support->cancel_at_period_end) {
            throw self::refuse('停止予約中のため変更できません。先に支援を再開してください。');
        }

        if (! $plan->is_accepting) {
            throw self::refuse('このプランは現在受付を停止しています。');
        }

        $subscriptionId = (string) $support->stripe_subscription_id;
        $price = $this->gateway->ensurePrice($plan);
        $itemId = $this->gateway->retrieveSubscription($subscriptionId)->itemId;

        if ($itemId === null) {
            throw new BillingException("Subscription {$subscriptionId} has no item to change.");
        }

        try {
            $data = $this->gateway->changeSubscriptionPrice($subscriptionId, $itemId, $price->priceId, $plan->price > $support->price);
        } catch (PaymentFailedException) {
            throw self::refuse('お支払いに失敗しました。カード情報をご確認ください。');
        }

        return $this->apply($support, $data);
    }

    private function apply(Support $support, SubscriptionData $data): Support
    {
        return $this->sync->apply($data) ?? $support->refresh();
    }

    private static function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['plan' => $message]);
    }
}
