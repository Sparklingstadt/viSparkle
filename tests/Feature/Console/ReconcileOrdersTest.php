<?php

use App\Billing\BillingException;
use App\Billing\FakeGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Shop\Orders;
use App\Services\Shop\OrderSync;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 500]);
    $this->product = Product::factory()->for($this->creator)->create(['price' => 1200, 'stock' => 10]);
});

/**
 * A pending order for 2 of the product, created $minutesAgo minutes ago, with a checkout session unless $withSession is false.
 *
 * @return array{0: Order, 1: string|null}
 */
function reconcilableOrder(int $minutesAgo, bool $withSession = true): array
{
    $buyer = User::factory()->create();
    $cart = collect([CartItem::query()->create(['user_id' => $buyer->id, 'product_id' => test()->product->id, 'quantity' => 2])]);
    $order = app(Orders::class)->reserve($buyer, test()->creator, $cart);
    $sessionId = $withSession ? fakeOrderCheckout(test()->gateway, $order) : null;
    $order->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

    return [$order, $sessionId];
}

it('is scheduled hourly', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'shop:reconcile-orders'));

    expect($event?->expression)->toBe('0 * * * *');
});

it('catches up a paid order whose webhook was missed', function () {
    [$order, $sessionId] = reconcilableOrder(90);
    $this->gateway->completePaymentCheckout((string) $sessionId);

    $this->artisan('shop:reconcile-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->product->refresh()->stock)->toBe(8);
});

it('expires and cancels a stale open checkout', function () {
    [$order, $sessionId] = reconcilableOrder(90);

    $this->artisan('shop:reconcile-orders')->assertSuccessful();

    expect($this->gateway->retrieveCheckoutSession((string) $sessionId)->status)->toBe('expired')
        ->and($order->refresh()->status)->toBe(OrderStatus::Canceled)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::Expired)
        ->and($this->product->refresh()->stock)->toBe(10);
});

it('cancels a pending order without a session', function () {
    [$order] = reconcilableOrder(90, withSession: false);

    $this->artisan('shop:reconcile-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Canceled)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::CheckoutError)
        ->and($this->product->refresh()->stock)->toBe(10);
});

it('leaves recent pending orders alone', function () {
    [$recent] = reconcilableOrder(30);
    [$recentWithoutSession] = reconcilableOrder(30, withSession: false);

    $this->artisan('shop:reconcile-orders')->assertSuccessful();

    expect($recent->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($recentWithoutSession->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->product->refresh()->stock)->toBe(6);
});

it('leaves orders that are not pending alone', function () {
    [$order, $sessionId] = reconcilableOrder(90);
    $this->gateway->completePaymentCheckout((string) $sessionId);
    app(OrderSync::class)->syncSession((string) $sessionId);

    $this->artisan('shop:reconcile-orders')->assertSuccessful();

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('keeps going after one order fails', function () {
    [$first, $firstSession] = reconcilableOrder(120);
    [$second, $secondSession] = reconcilableOrder(90);
    $this->gateway->completePaymentCheckout((string) $secondSession);
    $failing = new class($firstSession, app(Orders::class)) extends OrderSync
    {
        public function __construct(private readonly ?string $failOn, Orders $orders)
        {
            parent::__construct(app(FakeGateway::class), $orders);
        }

        public function syncSession(string $sessionId): ?Order
        {
            if ($sessionId === $this->failOn) {
                throw new BillingException('Stripe is down.');
            }

            return parent::syncSession($sessionId);
        }
    };
    app()->instance(OrderSync::class, $failing);
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (BillingException $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    $this->artisan('shop:reconcile-orders')->assertSuccessful();

    expect($first->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($second->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($reported)->toBe(['Stripe is down.']);
});
