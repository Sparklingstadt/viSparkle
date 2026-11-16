<?php

use App\Models\Plan;
use App\Models\Support;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('public');
    $this->creator = User::factory()->creator()->create();
    $this->actingAs($this->creator);
});

function planPayload(array $overrides = []): array
{
    return ['name' => 'ベーシック', 'description' => '説明', 'price' => 500, ...$overrides];
}

it('lists the plans with active supporter counts', function () {
    $plan = Plan::factory()->price(500)->create();
    Support::factory()->count(2)->forPlan($plan)->active()->create();
    Support::factory()->forPlan($plan)->ended()->create();

    $this->get(route('creator.plans.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/plans/index')
            ->has('plans', 1)
            ->where('plans.0.price', 500)
            ->where('plans.0.active_supporters_count', 2)
            ->where('plans.0.is_accepting', true));
});

it('creates a plan with a cover', function () {
    $this->post(route('creator.plans.store'), planPayload(['cover' => UploadedFile::fake()->image('c.jpg')]))
        ->assertRedirect(route('creator.plans.index'));

    $plan = Plan::query()->sole();
    expect($plan->price)->toBe(500)->and($plan->name)->toBe('ベーシック');
    Storage::disk('public')->assertExists($plan->cover_path);
});

it('validates the price', function (mixed $price) {
    $this->post(route('creator.plans.store'), planPayload(['price' => $price]))->assertSessionHasErrors('price');
})->with([99, 50001, 'abc', 500.5, null]);

it('rejects a duplicate price', function () {
    Plan::factory()->price(500)->create();

    $this->post(route('creator.plans.store'), planPayload())->assertSessionHasErrors('price');
});

it('limits plans to 10', function () {
    foreach (range(1, 10) as $i) {
        Plan::factory()->price($i * 100)->create();
    }

    $this->post(route('creator.plans.store'), planPayload(['price' => 5000]))->assertSessionHasErrors('price');
    expect(Plan::query()->count())->toBe(10);
});

it('updates name and description but never the price', function () {
    $plan = Plan::factory()->price(500)->create();

    $this->post(route('creator.plans.update', $plan), ['name' => '新名前', 'description' => '新説明', 'price' => 999, 'is_accepting' => true])
        ->assertRedirect(route('creator.plans.index'));

    expect($plan->fresh())->name->toBe('新名前')->description->toBe('新説明')->price->toBe(500);
});

it('stops accepting new supporters', function () {
    $plan = Plan::factory()->create();

    $this->post(route('creator.plans.update', $plan), ['name' => $plan->name, 'is_accepting' => false]);

    expect($plan->fresh()->is_accepting)->toBeFalse();
});

it('replaces the cover image', function () {
    $plan = Plan::factory()->create();
    $this->post(route('creator.plans.update', $plan), ['name' => 'a', 'is_accepting' => true, 'cover' => UploadedFile::fake()->image('1.jpg')]);
    $old = $plan->fresh()->cover_path;

    $this->post(route('creator.plans.update', $plan), ['name' => 'a', 'is_accepting' => true, 'cover' => UploadedFile::fake()->image('2.jpg')]);

    Storage::disk('public')->assertMissing($old);
    Storage::disk('public')->assertExists($plan->fresh()->cover_path);
});

it('refuses to delete a plan with active supporters', function () {
    $plan = Plan::factory()->create();
    Support::factory()->forPlan($plan)->active()->create();

    $this->delete(route('creator.plans.destroy', $plan))->assertSessionHasErrors('plan');

    expect($plan->fresh())->not->toBeNull();
});

it('deletes a plan with only ended supports', function () {
    $plan = Plan::factory()->create();
    $support = Support::factory()->forPlan($plan)->ended()->create();

    $this->delete(route('creator.plans.destroy', $plan))->assertRedirect(route('creator.plans.index'));

    expect($plan->fresh())->toBeNull()->and($support->fresh()->plan_id)->toBeNull();
});

it('strips exif from plan covers', function () {
    $this->post(route('creator.plans.store'), planPayload(['cover' => jpegWithExif(800, 400, 'c.jpg')]))
        ->assertSessionHasNoErrors();

    expect(Storage::disk('public')->get(Plan::query()->sole()->cover_path))->not->toContain('Exif');
});
