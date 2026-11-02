<?php

use App\Enums\OrderCancelReason;
use App\Events\Shop\OrderPaid;
use App\Events\Shop\OrderRefunded;
use App\Events\Shop\OrderShipped;
use App\Mail\Shop\OrderConfirmed;
use App\Mail\Shop\OrderReceived;
use App\Mail\Shop\OrderRefunded as OrderRefundedMail;
use App\Mail\Shop\OrderShipped as OrderShippedMail;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->buyer = User::factory()->create();
    $this->creator = Creator::factory()->payable()->create();
    $this->product = Product::factory()->for($this->creator)->create(['name' => 'マグカップ']);
    $this->order = Order::factory()->paid()->for($this->buyer)->for($this->creator)->withItem($this->product, 2)->create();
});

it('sends confirmation to buyer and creator when paid', function () {
    OrderPaid::dispatch($this->order);

    Mail::assertQueued(OrderConfirmed::class, fn (OrderConfirmed $mail) => $mail->hasTo($this->buyer->email));
    Mail::assertQueued(OrderReceived::class, fn (OrderReceived $mail) => $mail->hasTo($this->creator->user->email));
    Mail::assertQueuedCount(2);
});

it('renders the mails in Japanese with the order number, items and link', function () {
    $confirmed = (new OrderConfirmed($this->order))->render();
    $received = (new OrderReceived($this->order))->render();

    expect((new OrderConfirmed($this->order))->envelope()->subject)->toBe('ご注文を受け付けました')
        ->and((new OrderReceived($this->order))->envelope()->subject)->toBe('新しい注文が入りました')
        ->and((new OrderShippedMail($this->order))->envelope()->subject)->toBe('ご注文の商品を発送しました')
        ->and((new OrderRefundedMail($this->order))->envelope()->subject)->toBe('ご注文を取り消し、返金しました')
        ->and($confirmed)->toContain((string) $this->order->id)->toContain('マグカップ')->toContain(route('orders.show', $this->order))
        ->and($received)->toContain('マグカップ')->toContain(route('creator.orders.show', $this->order));
});

it('sends shipping and refund mails to the buyer', function () {
    OrderShipped::dispatch($this->order);
    OrderRefunded::dispatch($this->order);

    Mail::assertQueued(OrderShippedMail::class, fn ($mail) => $mail->hasTo($this->buyer->email));
    Mail::assertQueued(OrderRefundedMail::class, fn ($mail) => $mail->hasTo($this->buyer->email));
    Mail::assertQueuedCount(2);
});

it('renders the refund mail whether or not there is a reason', function () {
    $this->order->forceFill(['refunded_amount' => 3600, 'cancel_reason' => null])->save();
    expect((new OrderRefundedMail($this->order))->render())->toContain('3,600');

    $this->order->forceFill(['cancel_reason' => OrderCancelReason::Creator])->save();
    expect((new OrderRefundedMail($this->order))->render())->toContain('3,600');
});

it('skips mail to the buyer when the buyer account is gone', function () {
    $this->order->forceFill(['user_id' => null])->save();

    OrderPaid::dispatch($this->order);
    OrderShipped::dispatch($this->order);
    OrderRefunded::dispatch($this->order);

    Mail::assertNotQueued(OrderConfirmed::class);
    Mail::assertNotQueued(OrderShippedMail::class);
    Mail::assertNotQueued(OrderRefundedMail::class);
    Mail::assertQueued(OrderReceived::class, 1);
});

it('mentions downloads in the confirmation mail', function () {
    $work = Product::factory()->digital()->for($this->creator)->create();
    ProductFile::factory()->for($work)->create();
    $order = Order::factory()->paid()->for($this->buyer)->for($this->creator)->withItem($work)->create();

    expect((new OrderConfirmed($order))->render())->toContain('注文の画面からダウンロードできます')
        ->and((new OrderConfirmed($this->order))->render())->not->toContain('ダウンロード');
});

it('leaves the address out of the creator mail for a digital-only order', function () {
    $work = Product::factory()->digital()->for($this->creator)->create();
    $order = Order::factory()->paid()->for($this->buyer)->for($this->creator)->withItem($work)->create();
    $goods = Order::factory()->paid()->for($this->buyer)->for($this->creator)->withItem($this->product)->create(['shipping_name' => '山田 花子']);

    expect((new OrderReceived($order))->render())->not->toContain('お届け先')
        ->and((new OrderReceived($goods))->render())->toContain('お届け先: 山田 花子');
});
