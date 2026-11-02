<?php

namespace App\Mail\Shop;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderRefunded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'ご注文を取り消し、返金しました');
    }

    public function content(): Content
    {
        $this->order->loadMissing('items');

        return new Content(markdown: 'mail.shop.order-refunded');
    }
}
