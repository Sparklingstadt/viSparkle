<?php

use App\Billing\Data\ChargeData;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Services\Shop\DigitalAccess;
use App\Services\Shop\Orders;

beforeEach(function () {
    fakeBilling();
    $this->creator = Creator::factory()->payable()->create();
    $this->buyer = User::factory()->create();
    $this->work = Product::factory()->digital()->for($this->creator)->create(['price' => 800]);
    ProductFile::factory()->for($this->work)->create();
});

/**
 * A paid order of the buyer holding the work.
 */
function digitalPaidOrder(Product $work, string $state = 'paid'): Order
{
    return Order::factory()->{$state}()->withItem($work)->create([
        'user_id' => test()->buyer->id,
        'creator_id' => $work->creator_id,
        'subtotal' => $work->price,
        'total' => $work->price,
    ]);
}

function refusal(Order $order): ?string
{
    return app(DigitalAccess::class)->refusal(test()->buyer->refresh(), $order->refresh(), test()->work->refresh());
}

it('allows a paid or shipped order', function (string $state) {
    expect(refusal(digitalPaidOrder($this->work, $state)))->toBeNull();
})->with(['paid', 'shipped']);

it('tells the reason for an order that is not paid', function (string $state, string $reason) {
    $order = Order::factory()->{$state}()->withItem($this->work)->create(['user_id' => $this->buyer->id, 'creator_id' => $this->creator->id]);

    expect(refusal($order))->toBe($reason);
})->with([
    'pending' => ['pending', '支払いの確認後にダウンロードできます'],
    'canceled' => ['canceled', '取り消された注文のためダウンロードできません'],
    'refunded' => ['refunded', '返金済みのためダウンロードできません'],
]);

it('refuses after a full refund', function () {
    $order = digitalPaidOrder($this->work);

    app(Orders::class)->syncRefund($order, new ChargeData('ch_1', null, 800, false));

    expect(refusal($order))->toBe('返金済みのためダウンロードできません');
});

it('keeps access after a partial refund', function () {
    $order = digitalPaidOrder($this->work);

    app(Orders::class)->syncRefund($order, new ChargeData('ch_1', null, 100, false));

    expect($order->refresh()->refunded_amount)->toBe(100)
        ->and(refusal($order))->toBeNull();
});

it('refuses a disputed order', function () {
    $order = digitalPaidOrder($this->work);
    $order->forceFill(['disputed_at' => now()])->save();

    expect(refusal($order))->toBe('お支払いに問題があったため、ダウンロードできません');
});

it('refuses when the work is not published', function (Closure $unpublish) {
    $order = digitalPaidOrder($this->work);
    $unpublish($this->work);

    expect(refusal($order))->toBe('この作品は現在公開されていないため、ダウンロードできません');
})->with([
    'unpublished' => fn (Product $work) => $work->update(['is_published' => false]),
    'hidden' => fn (Product $work) => $work->forceFill(['hidden_at' => now(), 'hidden_reason' => '規約違反'])->save(),
    'deleted' => fn (Product $work) => $work->delete(),
]);

it('restores access when the work is published again', function () {
    $order = digitalPaidOrder($this->work);
    $this->work->update(['is_published' => false]);
    expect(refusal($order))->not->toBeNull();

    $this->work->update(['is_published' => true]);
    expect(refusal($order))->toBeNull();
});

it('refuses when the creator closed or is suspended', function (Closure $stop) {
    $order = digitalPaidOrder($this->work);
    $stop($this->creator);

    expect(refusal($order))->toBe('この作品は現在公開されていないため、ダウンロードできません');
})->with([
    'closed' => fn (Creator $creator) => $creator->forceFill(['closed_at' => now()])->save(),
    'suspended' => fn (Creator $creator) => $creator->user->forceFill(['suspended_at' => now()])->save(),
]);

it('refuses a suspended buyer', function () {
    $order = digitalPaidOrder($this->work);
    $this->buyer->forceFill(['suspended_at' => now()])->save();

    expect(refusal($order))->toBe('現在ダウンロードできません');
});

it('knows what the buyer has purchased', function () {
    $access = app(DigitalAccess::class);
    expect($access->hasPurchased($this->buyer, $this->work))->toBeFalse();

    Order::factory()->pending()->withItem($this->work)->create(['user_id' => $this->buyer->id, 'creator_id' => $this->creator->id]);
    Order::factory()->canceled()->withItem($this->work)->create(['user_id' => $this->buyer->id, 'creator_id' => $this->creator->id]);
    Order::factory()->refunded()->withItem($this->work)->create(['user_id' => $this->buyer->id, 'creator_id' => $this->creator->id]);
    Order::factory()->paid()->withItem($this->work)->create(['creator_id' => $this->creator->id]);
    expect($access->hasPurchased($this->buyer, $this->work))->toBeFalse();

    digitalPaidOrder($this->work, 'shipped');
    expect($access->hasPurchased($this->buyer, $this->work))->toBeTrue();
});
