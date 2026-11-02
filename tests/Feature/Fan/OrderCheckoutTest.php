<?php

use App\Billing\BillingException;
use App\Billing\Data\CheckoutSessionData;
use App\Billing\Data\PaymentCheckoutRequest;
use App\Billing\FakeGateway;
use App\Billing\PaymentGateway;
use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use App\Services\Shop\Cart;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create(['shipping_fee' => 600]);
    $this->buyer = User::factory()->create();
    $this->mug = Product::factory()->for($this->creator)->create(['name' => 'マグカップ', 'price' => 1800, 'stock' => 10]);
});

/**
 * Begin a checkout over HTTP as the buyer and return the fake session id taken from the redirect URL.
 */
function beginOrderCheckout(User $buyer, Creator $creator, array $data = []): string
{
    $response = test()->actingAs($buyer)->post(route('cart.checkout', $creator), $data);
    $response->assertRedirectContains('/dev/fake-checkout/');

    return basename((string) $response->headers->get('Location'));
}

it('shows the confirm page', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fan/cart-confirm')
            ->where('creator.slug', $this->creator->slug)
            ->where('group.subtotal', 3600)
            ->where('group.shipping_fee', 600)
            ->where('group.total', 4200)
            ->has('group.items', 1)
            ->where('support.presets', [100, 300, 500, 1000, 3000])
            ->where('support.min', 100)
            ->where('support.max', 50000));
});

it('hides the support on the confirm page when the creator does not accept it', function () {
    $this->creator->update(['accepts_support' => false]);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('support', null));
});

it('sends an empty or unpurchasable confirm back to the cart', function () {
    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))->assertRedirect(route('cart.index'));

    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $this->mug->variants()->update(['stock' => 1]);

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertRedirect(route('cart.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '在庫が足りません']);

    $this->mug->forceFill(['is_published' => false])->save();

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertRedirect(route('cart.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '現在購入できません']);
});

it('starts a checkout and reserves stock', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    $sessionId = beginOrderCheckout($this->buyer, $this->creator);

    $order = Order::sole();
    $session = $this->gateway->retrieveCheckoutSession($sessionId);

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($this->buyer->id)
        ->and($order->total)->toBe(1800 * 2 + 600)
        ->and($order->stripe_checkout_session_id)->toBe($sessionId)
        ->and($this->mug->fresh()->stock)->toBe(8)
        ->and($this->buyer->fresh()->stripe_customer_id)->not->toBeNull()
        ->and($session->metadata)->toBe([
            'app' => (string) config('fanbox.billing.idempotency_prefix'),
            'user_id' => (string) $this->buyer->id,
            'creator_id' => (string) $this->creator->id,
            'order_id' => (string) $order->id,
        ]);

    $data = $this->gateway->describeSession($sessionId);
    $raw = collect(Cache::get('fake-billing:state')['sessions'][$sessionId]);
    expect($data['mode'])->toBe('payment')
        ->and($data['amount'])->toBe(4200)
        ->and($raw['application_fee_amount'])->toBe((int) floor(4200 * config('fanbox.billing.platform_fee_percent') / 100))
        ->and($raw['line_items'])->toBe([
            ['name' => 'マグカップ', 'unit_amount' => 1800, 'quantity' => 2],
            ['name' => '送料', 'unit_amount' => 600, 'quantity' => 1],
        ])
        ->and($data['success_url'])->toBe(route('orders.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}')
        ->and($data['cancel_url'])->toBe(route('cart.index', ['canceled' => $order->id]));
});

it('adds no shipping line when the fee is zero', function () {
    $this->creator->forceFill(['shipping_fee' => 0])->save();
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $sessionId = beginOrderCheckout($this->buyer, $this->creator);

    expect(Cache::get('fake-billing:state')['sessions'][$sessionId]['line_items'])->toHaveCount(1);
});

it('refuses when the creator cannot sell', function () {
    $creator = Creator::factory()->create();
    $product = Product::factory()->for($creator)->create();
    app(Cart::class)->add($this->buyer, $product->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->post(route('cart.checkout', $creator))
        ->assertSessionHasErrors(['cart' => '現在購入できません']);

    expect(Order::count())->toBe(0)->and($product->fresh()->stock)->toBe(5);
});

it('refuses an empty cart and a sold-out item', function () {
    $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
        ->assertSessionHasErrors(['cart' => '現在購入できません']);

    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $this->mug->variants()->update(['stock' => 1]);
    $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
        ->assertSessionHasErrors(['cart' => '在庫が足りません']);

    expect(Order::count())->toBe(0);
});

it('says the item cannot be bought when it is unlisted, even if it is also short', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $this->mug->variants()->update(['stock' => 1]);
    $this->mug->forceFill(['is_published' => false])->save();

    $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
        ->assertSessionHasErrors(['cart' => '現在購入できません']);

    expect(Order::count())->toBe(0);
});

it('adds the support to the order and to checkout with its own fee', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    $sessionId = beginOrderCheckout($this->buyer, $this->creator, ['support_amount' => 500]);

    $order = Order::sole();
    $raw = Cache::get('fake-billing:state')['sessions'][$sessionId];
    expect($order)->support_amount->toBe(500)->support_fee_percent->toBe(5)->total->toBe(4200 + 500)
        ->and($raw['line_items'])->toBe([
            ['name' => 'マグカップ', 'unit_amount' => 1800, 'quantity' => 2],
            ['name' => '送料', 'unit_amount' => 600, 'quantity' => 1],
            ['name' => 'クリエイターへの応援', 'unit_amount' => 500, 'quantity' => 1],
        ])
        ->and($raw['application_fee_amount'])->toBe(intdiv(4200 * 10, 100) + intdiv(500 * 5, 100));
});

it('keeps checkout unchanged without support', function (array $data) {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    $sessionId = beginOrderCheckout($this->buyer, $this->creator, $data);

    $raw = Cache::get('fake-billing:state')['sessions'][$sessionId];
    expect(Order::sole())->support_amount->toBe(0)->total->toBe(4200)
        ->and($raw['line_items'])->toHaveCount(2)
        ->and($raw['application_fee_amount'])->toBe(intdiv(4200 * 10, 100));
})->with([
    'not sent' => [[]],
    'empty' => [['support_amount' => '']],
    'zero' => [['support_amount' => 0]],
]);

it('rejects support out of range', function (mixed $amount) {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->from(route('cart.confirm', $this->creator))
        ->post(route('cart.checkout', $this->creator), ['support_amount' => $amount])
        ->assertRedirect(route('cart.confirm', $this->creator))
        ->assertSessionHasErrors(['support_amount' => '応援額は0円、または100円から50,000円で指定してください。']);

    expect(Order::count())->toBe(0);
})->with([99, 1, 50001, -100, '300円', 100.5]);

it('accepts the bounds of the support', function (int $amount) {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    beginOrderCheckout($this->buyer, $this->creator, ['support_amount' => $amount]);

    expect(Order::sole()->support_amount)->toBe($amount);
})->with([100, 50000]);

it('hides the support and refuses it while support is turned off for the service', function () {
    config(['fanbox.billing.support_enabled' => false]);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('support', null));

    $this->actingAs($this->buyer)->from(route('cart.confirm', $this->creator))
        ->post(route('cart.checkout', $this->creator), ['support_amount' => 300])
        ->assertRedirect(route('cart.confirm', $this->creator))
        ->assertSessionHasErrors(['support_amount' => '現在、応援は受け付けていません']);
    expect(Order::count())->toBe(0);

    beginOrderCheckout($this->buyer, $this->creator);

    expect(Order::sole()->support_amount)->toBe(0);
});

it('refuses support when the creator does not accept it, but still sells', function () {
    $this->creator->update(['accepts_support' => false]);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->from(route('cart.confirm', $this->creator))
        ->post(route('cart.checkout', $this->creator), ['support_amount' => 300])
        ->assertRedirect(route('cart.confirm', $this->creator))
        ->assertSessionHasErrors(['support_amount' => 'このクリエイターは現在応援を受け付けていません']);
    expect(Order::count())->toBe(0);

    beginOrderCheckout($this->buyer, $this->creator, ['support_amount' => 0]);

    expect(Order::sole()->support_amount)->toBe(0);
});

it('does not carry the support over when replacing a pending order', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    beginOrderCheckout($this->buyer, $this->creator, ['support_amount' => 1000]);
    beginOrderCheckout($this->buyer, $this->creator);

    $orders = Order::orderBy('id')->get();
    expect($orders[0])->status->toBe(OrderStatus::Canceled)->cancel_reason->toBe(OrderCancelReason::Abandoned)
        ->and($orders[1])->support_amount->toBe(0)->total->toBe(4200);
});

it('replaces an unfinished checkout of the same creator', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 3);

    $first = beginOrderCheckout($this->buyer, $this->creator);
    $second = beginOrderCheckout($this->buyer, $this->creator);

    $orders = Order::orderBy('id')->get();

    expect($second)->not->toBe($first)
        ->and($orders)->toHaveCount(2)
        ->and($orders[0]->status)->toBe(OrderStatus::Canceled)
        ->and($orders[0]->cancel_reason)->toBe(OrderCancelReason::Abandoned)
        ->and($orders[1]->status)->toBe(OrderStatus::Pending)
        ->and($this->gateway->retrieveCheckoutSession($first)->status)->toBe('expired')
        ->and($this->gateway->retrieveCheckoutSession($second)->status)->toBe('open')
        ->and($this->mug->fresh()->stock)->toBe(7);
});

it('does not touch the pending order of another creator', function () {
    $other = Creator::factory()->payable()->create();
    $pin = Product::factory()->for($other)->create(['stock' => 4]);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);
    app(Cart::class)->add($this->buyer, $pin->variants()->firstOrFail(), 1);

    beginOrderCheckout($this->buyer, $this->creator);
    beginOrderCheckout($this->buyer, $other);

    expect(Order::where('status', OrderStatus::Pending)->count())->toBe(2);
});

it('refuses while a delayed payment is pending', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);
    $this->gateway->completePaymentCheckout($sessionId, paid: false);
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]));

    expect(Order::sole()->stripe_payment_intent_id)->not->toBeNull();

    $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
        ->assertRedirect(route('orders.show', Order::sole()))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'お支払い待ちの注文があります']);

    expect(Order::count())->toBe(1)
        ->and(Order::sole()->status)->toBe(OrderStatus::Pending)
        ->and($this->mug->fresh()->stock)->toBe(8);
});

it('cancels the order when Stripe fails', function () {
    $failing = new class(app(Repository::class)) extends FakeGateway
    {
        public function createPaymentCheckoutSession(PaymentCheckoutRequest $request): CheckoutSessionData
        {
            throw new BillingException('Stripe is down.');
        }
    };
    app()->instance(PaymentGateway::class, $failing);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);

    $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
        ->assertSessionHasErrors(['cart' => '決済の準備に失敗しました']);

    $order = Order::sole();
    expect($order->status)->toBe(OrderStatus::Canceled)
        ->and($order->cancel_reason)->toBe(OrderCancelReason::CheckoutError)
        ->and($this->mug->fresh()->stock)->toBe(10)
        ->and(CartItem::count())->toBe(1);
});

it('abandons the order when coming back from checkout', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);
    $order = Order::sole();

    $this->actingAs($this->buyer)->get(route('cart.index', ['canceled' => $order->id]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Canceled)
        ->and($order->fresh()->cancel_reason)->toBe(OrderCancelReason::Abandoned)
        ->and($this->gateway->retrieveCheckoutSession($sessionId)->status)->toBe('expired')
        ->and($this->mug->fresh()->stock)->toBe(10)
        ->and(CartItem::count())->toBe(1);
});

it('does not abandon someone elses order or one with a payment', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);
    $order = Order::sole();

    $this->actingAs(User::factory()->create())->get(route('cart.index', ['canceled' => $order->id]))->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    $this->gateway->completePaymentCheckout($sessionId);
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]));
    $this->actingAs($this->buyer)->get(route('cart.index', ['canceled' => $order->id]))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('completes a paid session on return', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);
    $this->gateway->completePaymentCheckout($sessionId);

    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]))
        ->assertRedirect(route('orders.show', Order::sole()))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'ご注文ありがとうございました']);

    $order = Order::sole();
    expect($order->status)->toBe(OrderStatus::Paid)
        ->and(CartItem::count())->toBe(0)
        ->and($this->mug->fresh()->stock)->toBe(8);
});

it('tells the buyer a delayed payment is awaited', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);
    $this->gateway->completePaymentCheckout($sessionId, paid: false);

    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => 'お支払いをお待ちしています']);

    expect(Order::sole()->status)->toBe(OrderStatus::Pending);
});

it('rejects completing someone elses session', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);
    $this->gateway->completePaymentCheckout($sessionId);

    $this->actingAs(User::factory()->create())->get(route('orders.checkout.complete', ['session_id' => $sessionId]))->assertNotFound();

    expect(Order::sole()->status)->toBe(OrderStatus::Pending);
});

it('404s a return without a session', function () {
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete'))->assertNotFound();
});

it('answers a failing payment service on return with a toast', function () {
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => 'cs_fake_missing']))
        ->assertRedirect(route('cart.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);
});

it('lets the fake checkout page pay, pay later and expire a payment session', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);

    $this->actingAs($this->buyer)->get("/dev/fake-checkout/{$sessionId}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('dev/fake-checkout')
            ->where('mode', 'payment')
            ->where('amount', 2400));

    $this->actingAs($this->buyer)->post("/dev/fake-checkout/{$sessionId}", ['action' => 'expire'])
        ->assertRedirect(route('cart.index'));
    expect(Order::sole()->status)->toBe(OrderStatus::Canceled)
        ->and(Order::sole()->cancel_reason)->toBe(OrderCancelReason::Expired)
        ->and($this->mug->fresh()->stock)->toBe(10);

    $second = beginOrderCheckout($this->buyer, $this->creator);
    $this->actingAs($this->buyer)->post("/dev/fake-checkout/{$second}", ['action' => 'later'])
        ->assertRedirectContains(route('orders.checkout.complete'));
    expect($this->gateway->retrieveCheckoutSession($second)->paymentStatus)->toBe('unpaid');
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $second]));
    expect(Order::latest('id')->first()->stripe_payment_intent_id)->not->toBeNull();

    $this->gateway->settleAsyncPayment($second, true);
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $second]));
    expect(Order::latest('id')->first()->status)->toBe(OrderStatus::Paid);
});

it('pays on the fake checkout page', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);

    $this->actingAs($this->buyer)->post("/dev/fake-checkout/{$sessionId}", ['action' => 'pay'])
        ->assertRedirectContains(route('orders.checkout.complete'));

    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]));
    expect(Order::sole()->status)->toBe(OrderStatus::Paid);
});

it('refuses while another checkout of the same buyer and creator is starting', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $lock = Cache::lock("order-checkout:{$this->buyer->id}:{$this->creator->id}", 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
            ->assertSessionHasErrors(['cart' => '現在購入できません']);
    } finally {
        $lock->release();
    }

    expect(Order::count())->toBe(0)->and($this->mug->fresh()->stock)->toBe(10);
});

it('keeps a previous order whose checkout was paid but not yet synced', function () {
    $sticker = Product::factory()->for($this->creator)->create(['price' => 300, 'stock' => 4]);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $first = beginOrderCheckout($this->buyer, $this->creator);
    $this->gateway->completePaymentCheckout($first);
    // The paid order empties its products from the cart once synced; this one is still in it.
    app(Cart::class)->add($this->buyer, $sticker->variants()->firstOrFail(), 1);

    beginOrderCheckout($this->buyer, $this->creator);

    $orders = Order::orderBy('id')->get();
    expect($orders)->toHaveCount(2)
        ->and($orders[0]->status)->toBe(OrderStatus::Paid)
        ->and($orders[1]->status)->toBe(OrderStatus::Pending)
        ->and($orders[1]->items->pluck('product_id')->all())->toBe([$sticker->id])
        ->and($this->gateway->refundedPaymentIntents())->toBe([])
        ->and($this->mug->fresh()->stock)->toBe(8);
});

it('refuses when the previous checkout turns out to await a delayed payment', function () {
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 2);
    $first = beginOrderCheckout($this->buyer, $this->creator);
    $this->gateway->completePaymentCheckout($first, paid: false);

    $this->actingAs($this->buyer)->post(route('cart.checkout', $this->creator))
        ->assertRedirect(route('orders.show', Order::sole()))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'お支払い待ちの注文があります']);

    expect(Order::count())->toBe(1)->and(Order::sole()->status)->toBe(OrderStatus::Pending);
});

/**
 * A published digital work of the creator with one file.
 */
function checkoutWork(Creator $creator, int $price = 800): Product
{
    $work = Product::factory()->digital()->for($creator)->create(['name' => 'イラスト集', 'price' => $price]);
    ProductFile::factory()->for($work)->create();

    return $work;
}

it('checks out a digital-only cart without an address', function () {
    app(Cart::class)->add($this->buyer, checkoutWork($this->creator)->variants()->firstOrFail(), 1);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);

    expect($this->gateway->sessionCollectsShipping($sessionId))->toBeFalse();

    $this->gateway->completePaymentCheckout($sessionId);
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]))
        ->assertRedirect(route('orders.show', Order::sole()));

    expect(Order::sole())
        ->status->toBe(OrderStatus::Paid)
        ->shipping_name->toBeNull()
        ->shipping_fee->toBe(0)
        ->total->toBe(800);
});

it('collects an address when the cart mixes kinds', function () {
    app(Cart::class)->add($this->buyer, checkoutWork($this->creator)->variants()->firstOrFail(), 1);
    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);
    $sessionId = beginOrderCheckout($this->buyer, $this->creator);

    expect($this->gateway->sessionCollectsShipping($sessionId))->toBeTrue();

    $this->gateway->completePaymentCheckout($sessionId);
    $this->actingAs($this->buyer)->get(route('orders.checkout.complete', ['session_id' => $sessionId]));

    expect(Order::sole())->shipping_name->not->toBeNull()->shipping_fee->toBe(600);
});

it('warns that digital works cannot be canceled', function () {
    app(Cart::class)->add($this->buyer, checkoutWork($this->creator)->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertInertia(fn (Assert $page) => $page
            ->where('has_digital', true)
            ->where('has_physical', false)
            ->where('group.shipping_fee', 0));

    app(Cart::class)->add($this->buyer, $this->mug->variants()->firstOrFail(), 1);

    $this->actingAs($this->buyer)->get(route('cart.confirm', $this->creator))
        ->assertInertia(fn (Assert $page) => $page->where('has_digital', true)->where('has_physical', true));
});
