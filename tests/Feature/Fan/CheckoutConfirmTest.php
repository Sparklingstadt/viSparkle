<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    fakeBilling();
    $this->creator = Creator::factory()->payable()->create();
    $this->plan = Plan::factory()->for($this->creator)->price(1000)->create();
    $this->fan = User::factory()->create();
});

function confirmRoute(Creator $creator, Plan $plan): string
{
    return route('supports.confirm', [$creator, $plan]);
}

it('shows the plan, price and renewal terms before payment', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00', 'Asia/Tokyo'));

    $this->actingAs($this->fan)->get(confirmRoute($this->creator, $this->plan))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fan/checkout-confirm')
            ->where('creator.slug', $this->creator->slug)
            ->where('plan.id', $this->plan->id)
            ->where('plan.name', $this->plan->name)
            ->where('plan.price', 1000)
            ->where('renewalDay', '15日'));
});

it('notes the month-end rule only when the renewal day is 29 or later', function (string $date, string $expected) {
    Carbon::setTestNow(Carbon::parse($date.' 12:00', 'Asia/Tokyo'));

    $this->actingAs($this->fan)->get(confirmRoute($this->creator, $this->plan))
        ->assertInertia(fn ($page) => $page->where('renewalDay', $expected));
})->with([
    '28' => ['2026-11-28', '28日'],
    '29' => ['2026-11-29', '29日（月末日がない月は月末）'],
    '30' => ['2026-11-30', '30日（月末日がない月は月末）'],
    '31' => ['2026-12-31', '31日（月末日がない月は月末）'],
]);

it('redirects with a toast in production with a live key until the legal details are ready', function () {
    config([
        'fanbox.billing.stripe.secret' => 'sk_live_x',
        'legal.seller_name' => '',
        'legal.email' => 'legal@example.com',
    ]);
    app()->detectEnvironment(fn () => 'production');
    Log::spy();

    $this->actingAs($this->fan)->get(confirmRoute($this->creator, $this->plan))
        ->assertRedirect(route('creators.show', $this->creator))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => '現在お申し込みを受け付けていません。']);
    Log::shouldHaveReceived('error')->with('legal.not_ready')->once();
});

it('still refuses a direct post for a support that cannot begin', function () {
    $this->plan->forceFill(['is_accepting' => false])->save();

    $this->actingAs($this->fan)->post(route('supports.store', [$this->creator, $this->plan]))
        ->assertSessionHasErrors(['plan' => 'このプランは現在受付を停止しています。']);
});

it('redirects back to the creator page with a toast when the support cannot begin', function (callable $arrange, string $message) {
    $arrange($this);

    $this->actingAs($this->fan)->get(confirmRoute($this->creator, $this->plan))
        ->assertRedirect(route('creators.show', $this->creator))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => $message]);
})->with([
    'own creator' => [fn ($t) => $t->creator->forceFill(['user_id' => $t->fan->id])->save(), '自分自身を支援することはできません。'],
    'not payable' => [fn ($t) => $t->creator->forceFill(['stripe_account_id' => null])->save(), 'このクリエイターは現在支援を受け付けていません。'],
    'not accepting' => [fn ($t) => $t->plan->forceFill(['is_accepting' => false])->save(), 'このプランは現在受付を停止しています。'],
    'already supporting' => [fn ($t) => Support::factory()->for($t->fan)->forPlan($t->plan)->active()->create(), 'すでに支援中です。プランの変更は「このプランに変更」から行ってください。'],
]);

it('returns 404 for a plan of another creator', function () {
    $other = Plan::factory()->for(Creator::factory()->payable()->create())->create();

    $this->actingAs($this->fan)->get(confirmRoute($this->creator, $other))->assertNotFound();
});

it('sends guests to login', function () {
    $this->get(confirmRoute($this->creator, $this->plan))->assertRedirect(route('login'));
});
