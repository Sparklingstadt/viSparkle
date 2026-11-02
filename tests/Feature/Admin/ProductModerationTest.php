<?php

use App\Enums\ReportTarget;
use App\Models\AdminAction;
use App\Models\Creator;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->creator = Creator::factory()->payable()->create();
    $this->product = Product::factory()->for($this->creator)->create();
});

it('hides a reported product from the shop', function () {
    Report::factory()->create(['target_type' => ReportTarget::Product, 'target_id' => $this->product->id]);

    $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), ['hidden' => true, 'reason' => '商標権侵害'])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '商品を非公開にしました。']);

    $this->product->refresh();
    expect($this->product->hidden_at)->not->toBeNull()
        ->and($this->product->hidden_reason)->toBe('商標権侵害')
        ->and(Product::query()->listed()->count())->toBe(0)
        ->and(AdminAction::sole())->action->toBe('product.hidden')->note->toBe('商標権侵害');

    $this->get(route('shop.products.show', [$this->creator, $this->product->id]))->assertNotFound();
});

it('puts a hidden product back', function () {
    $this->product->forceFill(['hidden_at' => now(), 'hidden_reason' => 'x'])->save();

    $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), ['hidden' => false])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'success', 'message' => '商品を公開に戻しました。']);

    $this->product->refresh();
    expect($this->product->hidden_at)->toBeNull()
        ->and($this->product->hidden_reason)->toBeNull()
        ->and(AdminAction::sole()->action)->toBe('product.unhidden');
    $this->get(route('shop.products.show', [$this->creator, $this->product->id]))->assertOk();
});

it('requires a reason to hide', function () {
    $this->actingAs($this->admin)->put(route('admin.products.update', $this->product), ['hidden' => true, 'reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($this->product->refresh()->hidden_at)->toBeNull();
});

it('shows the product with its reports', function () {
    Report::factory()->create(['target_type' => ReportTarget::Product, 'target_id' => $this->product->id, 'body' => '偽物です']);
    Report::factory()->create(['target_type' => ReportTarget::Post, 'target_id' => $this->product->id]);

    $this->actingAs($this->admin)->get(route('admin.products.show', $this->product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/product')
            ->where('product.id', $this->product->id)
            ->where('product.hidden', false)
            ->has('reports', 1)
            ->where('reports.0.body', '偽物です'));
});

it('shows a hidden product too', function () {
    $this->product->forceFill(['hidden_at' => now(), 'hidden_reason' => 'x'])->save();

    $this->actingAs($this->admin)->get(route('admin.products.show', $this->product))
        ->assertInertia(fn ($page) => $page->where('product.hidden', true)->where('product.hidden_reason', 'x'));
});

it('links a product report to the admin product page', function () {
    Report::factory()->create(['target_type' => ReportTarget::Product, 'target_id' => $this->product->id]);

    $this->actingAs($this->admin)->get(route('admin.reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('reports.data.0.target', ['label' => $this->product->name, 'url' => route('admin.products.show', $this->product)]));
});

it('forbids non-admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.products.show', $this->product))->assertNotFound();
    $this->actingAs($user)->put(route('admin.products.update', $this->product), ['hidden' => true, 'reason' => 'x'])->assertNotFound();

    expect($this->product->refresh()->hidden_at)->toBeNull();
});
