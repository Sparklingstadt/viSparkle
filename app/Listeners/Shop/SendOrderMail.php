<?php

namespace App\Listeners\Shop;

use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderRefunded;
use App\Events\Shop\OrderShipped;
use App\Mail\Shop\OrderConfirmed;
use App\Mail\Shop\OrderReceived;
use App\Mail\Shop\OrderRefunded as OrderRefundedMail;
use App\Mail\Shop\OrderShipped as OrderShippedMail;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the order mails. Handlers are found by Laravel's event discovery from the typed `handle*` methods.
 * The buyer's mails are skipped once their account is gone (`user_id` is null).
 */
class SendOrderMail
{
    public function handleOrderPaid(OrderPaid $event): void
    {
        $this->toBuyer($event->order, new OrderConfirmed($event->order));

        $creatorUser = $event->order->creator?->user;

        if ($creatorUser) {
            Mail::to($creatorUser)->send(new OrderReceived($event->order));
        }
    }

    public function handleOrderShipped(OrderShipped $event): void
    {
        $this->toBuyer($event->order, new OrderShippedMail($event->order));
    }

    public function handleOrderRefunded(OrderRefunded $event): void
    {
        $this->toBuyer($event->order, new OrderRefundedMail($event->order));
    }

    private function toBuyer(Order $order, OrderConfirmed|OrderShippedMail|OrderRefundedMail $mail): void
    {
        $buyer = $order->user;

        if ($buyer) {
            Mail::to($buyer)->send($mail);
        }
    }
}
