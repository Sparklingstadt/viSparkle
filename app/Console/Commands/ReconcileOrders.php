<?php

namespace App\Console\Commands;

use App\Billing\PaymentGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Shop\Orders;
use App\Services\Shop\OrderSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('shop:reconcile-orders')]
#[Description('Bring pending orders older than an hour in line with Stripe, catching up missed webhooks and releasing abandoned stock')]
class ReconcileOrders extends Command
{
    public function handle(OrderSync $sync, Orders $orders, PaymentGateway $gateway): int
    {
        $failed = 0;
        $checked = 0;

        // By id, not by offset: the orders leave the "pending" set as they are handled.
        $pending = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<', now()->subHour())
            ->lazyById();

        foreach ($pending as $order) {
            $checked++;

            try {
                if ($order->stripe_checkout_session_id === null) {
                    $orders->cancel($order, OrderCancelReason::CheckoutError);

                    continue;
                }

                $sessionId = $order->stripe_checkout_session_id;
                $sync->syncSession($sessionId);

                if ($order->refresh()->status !== OrderStatus::Pending) {
                    continue;
                }

                // Still waiting on an open session after an hour: close it, then settle on what Stripe says now.
                if ($gateway->retrieveCheckoutSession($sessionId)->status === 'open') {
                    $gateway->expireCheckoutSession($sessionId);
                    $sync->syncSession($sessionId);

                    continue;
                }

                // A failed delayed payment leaves the session complete and unpaid: only the payment intent tells (its webhook may be lost).
                $paymentIntentId = $order->refresh()->stripe_payment_intent_id;

                if ($paymentIntentId !== null) {
                    match ($gateway->retrievePaymentIntentStatus($paymentIntentId)) {
                        'canceled', 'requires_payment_method' => $orders->cancel($order, OrderCancelReason::PaymentFailed),
                        'succeeded' => $sync->syncSession($sessionId),
                        default => null,
                    };
                }
            } catch (Throwable $e) {
                // One broken order must not stop the hourly run for the others.
                $failed++;
                report($e);
            }
        }

        $this->info("Checked {$checked} pending order(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
