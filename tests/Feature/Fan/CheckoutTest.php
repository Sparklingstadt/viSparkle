<?php

use App\Billing\BillingException;
use App\Billing\Data\CheckoutRequest;
use App\Billing\Data\PriceRef;
use App\Billing\PaymentGateway;
use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Entitlement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->gateway = fakeBilling();
    $this->creator = Creator::factory()->payable()->create();
    $this->plan = Plan::factory()->for($this->creator)->price(1000)->create();
    $this->fan = User::factory()->create();
});

function checkoutRoute(Creator $creator, Plan $plan): string
{
    return route('supports.store', [$creator, $plan]);
}

/**
 * Begin a checkout as the fan and return the fake session id taken from the redirect URL.
 */
function beginCheckout(User $fan, Creator $creator, Plan $plan): string
{
    $response = test()->actingAs($fan)->post(checkoutRoute($creator, $plan));
    $response->assertRedirectContains('/dev/fake-checkout/');

    return basename((string) $response->headers->get('Location'));
}

it('sends the fan to stripe checkout', function () {
    $response = $this->actingAs($this->fan)->post(checkoutRoute($this->creator, $this->plan));

    $response->assertRedirectContains('/dev/fake-checkout/');
    expect($this->fan->fresh()->stripe_customer_id)->not->toBeNull()
        ->and(Support::count())->toBe(0);
});

it('answers inertia requests with a location redirect', function () {
    $response = $this->actingAs($this->fan)->post(checkoutRoute($this->creator, $this->plan), [], ['X-Inertia' => 'true']);

    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toContain('/dev/fake-checkout/');
});

it('reuses the fans stripe customer', function () {
    beginCheckout($this->fan, $this->creator, $this->plan);
    $customerId = $this->fan->fresh()->stripe_customer_id;
    beginCheckout($this->fan, $this->creator, $this->plan);

    expect($this->fan->fresh()->stripe_customer_id)->toBe($customerId);
});

it('activates the support when the fan returns from checkout', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(1000)->create();
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);
    $this->gateway->completeCheckout($sessionId);

    $this->actingAs($this->fan)->get(route('supports.checkout.complete', ['session_id' => $sessionId]))
        ->assertRedirect(route('creators.show', $this->creator))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => "{$this->creator->display_name} さんの支援を開始しました。"]);

    $support = Support::sole();
    expect($support->status)->toBe(SupportStatus::Active)
        ->and($support->plan_id)->toBe($this->plan->id)
        ->and(Entitlement::for($this->fan)->canView($post))->toBeTrue();
});

it('tells the fan when payment is not complete', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);

    $this->actingAs($this->fan)->get(route('supports.checkout.complete', ['session_id' => $sessionId]))
        ->assertRedirect(route('creators.show', $this->creator))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'お支払いが完了していません。']);

    expect(Support::count())->toBe(0);
});

it('rejects completing someone elses session', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);
    $this->gateway->completeCheckout($sessionId);

    $this->actingAs(User::factory()->create())
        ->get(route('supports.checkout.complete', ['session_id' => $sessionId]))
        ->assertNotFound();

    expect(Support::count())->toBe(0);
});

it('requires login and a session id to complete', function () {
    $this->get(route('supports.checkout.complete', ['session_id' => 'cs_x']))->assertRedirect(route('login'));
    $this->actingAs($this->fan)->get(route('supports.checkout.complete'))->assertNotFound();
});

it('refuses creators who cannot accept supports', function () {
    $creator = Creator::factory()->create();
    $plan = Plan::factory()->for($creator)->create();

    $this->actingAs($this->fan)->post(checkoutRoute($creator, $plan))
        ->assertSessionHasErrors(['plan' => 'このクリエイターは現在支援を受け付けていません。']);

    $unverified = Creator::factory()->create();
    $unverified->forceFill(['stripe_account_id' => 'acct_fake_x', 'payouts_enabled' => false])->save();
    $this->actingAs($this->fan)->post(checkoutRoute($unverified, Plan::factory()->for($unverified)->create()))
        ->assertSessionHasErrors('plan');

    expect(Support::count())->toBe(0);
});

it('refuses a second checkout while supporting', function () {
    Support::factory()->for($this->fan)->forPlan($this->plan)->active()->create();

    $this->actingAs($this->fan)->post(checkoutRoute($this->creator, Plan::factory()->for($this->creator)->price(300)->create()))
        ->assertSessionHasErrors(['plan' => 'すでに支援中です。プランの変更は「このプランに変更」から行ってください。']);
});

it('expires the previous open checkout session', function () {
    $first = beginCheckout($this->fan, $this->creator, $this->plan);
    $second = beginCheckout($this->fan, $this->creator, $this->plan);

    expect($this->gateway->describeSession($first)['status'])->toBe('expired')
        ->and($this->gateway->describeSession($second)['status'])->toBe('open');
});

it('forgets the open session once the checkout completes', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);
    $this->gateway->completeCheckout($sessionId);
    $key = "checkout:{$this->fan->id}:{$this->creator->id}";
    expect(Cache::get($key))->toBe($sessionId);

    $this->actingAs($this->fan)->get(route('supports.checkout.complete', ['session_id' => $sessionId]));

    expect(Cache::has($key))->toBeFalse();
});

it('sends the metadata, destination and expiry to the gateway', function () {
    $captured = null;
    $real = $this->gateway;
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createCustomer')->andReturnUsing(fn ($user) => $real->createCustomer($user));
    $gateway->shouldReceive('ensurePrice')->andReturnUsing(fn ($plan) => $real->ensurePrice($plan));
    $gateway->shouldReceive('createCheckoutSession')->once()->andReturnUsing(function (CheckoutRequest $request) use (&$captured, $real) {
        $captured = $request;

        return $real->createCheckoutSession($request);
    });
    app()->instance(PaymentGateway::class, $gateway);
    $this->freezeTime();

    $this->actingAs($this->fan)->post(checkoutRoute($this->creator, $this->plan));

    expect($captured)->toBeInstanceOf(CheckoutRequest::class)
        ->and($captured->metadata)->toBe(['user_id' => (string) $this->fan->id, 'creator_id' => (string) $this->creator->id, 'plan_id' => (string) $this->plan->id])
        ->and($captured->destinationAccountId)->toBe($this->creator->stripe_account_id)
        ->and($captured->feePercent)->toBe(10)
        ->and($captured->cancelUrl)->toBe(route('creators.show', $this->creator))
        ->and($captured->successUrl)->toBe(route('supports.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}')
        // One minute past the configured 30 so Stripe's own clock still sees at least 30 minutes.
        ->and($captured->expiresAt->getTimestamp())->toBe(now()->addMinutes(31)->getTimestamp());

    // The open-session key itself still lasts the configured 30 minutes.
    $key = "checkout:{$this->fan->id}:{$this->creator->id}";
    $this->travel(29)->minutes();
    expect(Cache::has($key))->toBeTrue();
    $this->travel(2)->minutes();
    expect(Cache::has($key))->toBeFalse();
});

it('refuses supporting yourself', function () {
    $this->actingAs($this->creator->user)->post(checkoutRoute($this->creator, $this->plan))
        ->assertSessionHasErrors(['plan' => '自分自身を支援することはできません。']);

    expect(Support::count())->toBe(0);
});

it('refuses a plan that is not accepting', function () {
    $closed = Plan::factory()->for($this->creator)->price(2000)->notAccepting()->create();

    $this->actingAs($this->fan)->post(checkoutRoute($this->creator, $closed))
        ->assertSessionHasErrors(['plan' => 'このプランは現在受付を停止しています。']);

    expect(Support::count())->toBe(0);
});

it('404s a plan that belongs to another creator', function () {
    $other = Plan::factory()->create();

    $this->actingAs($this->fan)->post(checkoutRoute($this->creator, $other))->assertNotFound();
});

it('requires login and a verified email', function () {
    $this->post(checkoutRoute($this->creator, $this->plan))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())
        ->post(checkoutRoute($this->creator, $this->plan))
        ->assertRedirect(route('verification.notice'));
});

it('shows a toast when stripe is unreachable', function () {
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createCustomer')->andReturn('cus_x');
    $gateway->shouldReceive('ensurePrice')->andReturn(new PriceRef('prod_x', 'price_x'));
    $gateway->shouldReceive('createCheckoutSession')->andThrow(new BillingException('down'));
    app()->instance(PaymentGateway::class, $gateway);

    $this->actingAs($this->fan)->from(route('creators.show', $this->creator))
        ->post(checkoutRoute($this->creator, $this->plan))
        ->assertRedirect(route('creators.show', $this->creator))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '決済サービスに接続できませんでした。時間をおいてお試しください。']);

    expect(Support::count())->toBe(0);
});

it('shows the fake checkout page', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);

    $this->actingAs($this->fan)->get(route('dev.fake-checkout.show', $sessionId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dev/fake-checkout')
            ->where('amount', 1000)
            ->where('plan_name', $this->plan->name)
            ->where('status', 'open')
            ->where('cancel_url', route('creators.show', $this->creator)));

    $this->actingAs($this->fan)->get(route('dev.fake-checkout.show', 'cs_missing'))->assertNotFound();
});

it('completes the fake checkout page', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);

    $this->actingAs($this->fan)->post(route('dev.fake-checkout.pay', $sessionId))
        ->assertRedirect(route('supports.checkout.complete', ['session_id' => $sessionId]));

    expect($this->gateway->describeSession($sessionId)['status'])->toBe('complete');
});

it('records the first payment when the fake checkout page is paid', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);

    $this->actingAs($this->fan)->post(route('dev.fake-checkout.pay', $sessionId));

    $payment = Payment::sole();
    expect($payment->amount)->toBe(1000)
        ->and($payment->user_id)->toBe($this->fan->id)
        ->and($payment->creator_id)->toBe($this->creator->id)
        ->and($payment->support_id)->toBe(Support::sole()->id);
});

it('hides another users fake checkout session', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('dev.fake-checkout.show', $sessionId))->assertNotFound();
    $this->actingAs($other)->post(route('dev.fake-checkout.pay', $sessionId))->assertNotFound();

    expect($this->gateway->describeSession($sessionId)['status'])->toBe('open');
});

it('requires login for the fake checkout page', function () {
    $this->get(route('dev.fake-checkout.show', 'cs_x'))->assertRedirect(route('login'));
    $this->post(route('dev.fake-checkout.pay', 'cs_x'))->assertRedirect(route('login'));
});

it('answers can-accept-supports for a fresh creator model', function () {
    expect((new Creator)->canAcceptSupports())->toBeFalse();
});

it('limits the checkout return separately, at 30 a minute', function () {
    $sessionId = beginCheckout($this->fan, $this->creator, $this->plan);
    $url = route('supports.checkout.complete', ['session_id' => $sessionId]);

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($this->fan)->get($url)->assertRedirect();
    }

    $this->actingAs($this->fan)->get($url)->assertTooManyRequests();
});

it('refuses checkout in production with a live stripe key until the legal details are ready', function (string $secret, bool $ready, bool $allowed) {
    config([
        'fanbox.billing.stripe.secret' => $secret,
        'legal.seller_name' => $ready ? '山田 太郎' : '',
        'legal.responsible_person' => '山田 太郎',
        'legal.address' => '',
        'legal.phone' => '',
        'legal.email' => 'legal@example.com',
        'legal.disclose_on_request' => true,
    ]);
    app()->detectEnvironment(fn () => 'production');
    Log::spy();

    // Called directly: outside the testing environment the HTTP stack would also enforce CSRF.
    $begin = fn () => app(CheckoutService::class)->begin($this->fan, $this->plan);

    if ($allowed) {
        expect($begin())->toContain('/dev/fake-checkout/');
        Log::shouldNotHaveReceived('error');
    } else {
        expect($begin)->toThrow(ValidationException::class, '現在お申し込みを受け付けていません。');
        Log::shouldHaveReceived('error')->with('legal.not_ready')->once();
        expect($this->fan->fresh()->stripe_customer_id)->toBeNull();
    }
})->with([
    'live key, not ready' => ['sk_live_x', false, false],
    'restricted live key, not ready' => ['rk_live_x', false, false],
    'test key, not ready' => ['sk_test_x', false, true],
    'live key, ready' => ['sk_live_x', true, true],
]);
