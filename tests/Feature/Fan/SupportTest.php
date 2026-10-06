<?php

use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Entitlement;
use App\Services\SupportService;

beforeEach(function () {
    $this->creator = Creator::factory()->create();
    $this->low = Plan::factory()->for($this->creator)->price(300)->create();
    $this->high = Plan::factory()->for($this->creator)->price(1000)->create();
    $this->fan = User::factory()->create();
});

function supportRoute(Creator $creator, Plan $plan): string
{
    return route('supports.store', [$creator, $plan]);
}

it('starts a support and unlocks posts', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(1000)->create();

    $this->actingAs($this->fan)->post(supportRoute($this->creator, $this->high))
        ->assertRedirect(route('creators.show', $this->creator));

    $support = Support::sole();
    expect($support)->user_id->toBe($this->fan->id)->plan_id->toBe($this->high->id)->price->toBe(1000)
        ->status->toBe(SupportStatus::Active)
        ->and(Entitlement::for($this->fan)->canView($post))->toBeTrue();
});

it('switches plan by ending the previous support', function () {
    $this->actingAs($this->fan)->post(supportRoute($this->creator, $this->low));
    $this->actingAs($this->fan)->post(supportRoute($this->creator, $this->high));

    expect(Support::active()->sole()->plan_id)->toBe($this->high->id)
        ->and(Support::where('status', SupportStatus::Ended)->sole())
        ->plan_id->toBe($this->low->id)
        ->ended_at->not->toBeNull();
});

it('is idempotent for the same plan', function () {
    $service = app(SupportService::class);

    $first = $service->start($this->fan, $this->high);
    $second = $service->start($this->fan, $this->high);

    expect($second->is($first))->toBeTrue()->and(Support::count())->toBe(1);
});

it('keeps a single active support across repeated switches', function () {
    $service = app(SupportService::class);

    foreach ([$this->low, $this->high, $this->low, $this->high] as $plan) {
        $service->start($this->fan, $plan);
    }

    expect(Support::active()->where('user_id', $this->fan->id)->count())->toBe(1)
        ->and(Support::count())->toBe(4);
});

it('refuses supporting yourself', function () {
    $this->actingAs($this->creator->user)->post(supportRoute($this->creator, $this->low))
        ->assertSessionHasErrors('plan');

    expect(Support::count())->toBe(0);
});

it('refuses a plan that is not accepting', function () {
    $closed = Plan::factory()->for($this->creator)->price(2000)->notAccepting()->create();

    $this->actingAs($this->fan)->post(supportRoute($this->creator, $closed))->assertSessionHasErrors('plan');

    expect(Support::count())->toBe(0);
});

it('404s a plan that belongs to another creator', function () {
    $other = Plan::factory()->create();

    $this->actingAs($this->fan)->post(supportRoute($this->creator, $other))->assertNotFound();
});

it('requires login and a verified email', function () {
    $this->post(supportRoute($this->creator, $this->low))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())
        ->post(supportRoute($this->creator, $this->low))
        ->assertRedirect(route('verification.notice'));
});

it('ends a support and relocks posts', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(300)->create();
    $support = app(SupportService::class)->start($this->fan, $this->low);

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))->assertRedirect();

    expect($support->fresh()->status)->toBe(SupportStatus::Ended)
        ->and(Entitlement::for($this->fan)->canView($post))->toBeFalse();
});

it('404s ending someone elses support', function () {
    $support = app(SupportService::class)->start(User::factory()->create(), $this->low);

    $this->actingAs($this->fan)->delete(route('supports.destroy', $support))->assertNotFound();
    expect($support->fresh()->status)->toBe(SupportStatus::Active);
});

it('lists active supports on the supporting page', function () {
    app(SupportService::class)->start($this->fan, $this->low);
    Support::factory()->for($this->fan)->ended()->create();

    $this->actingAs($this->fan)->get(route('supporting.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('fan/supporting')
            ->has('supports', 1)
            ->where('supports.0.creator.slug', $this->creator->slug)
            ->where('supports.0.plan_name', $this->low->name)
            ->where('supports.0.price', 300));
});
