<?php

use App\Enums\ReportStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Product;
use App\Models\Report;
use App\Models\Support;
use App\Models\User;

dataset('admin routes', [
    'dashboard' => ['get', fn () => route('admin.dashboard')],
    'reports' => ['get', fn () => route('admin.reports.index')],
    'report update' => ['patch', fn () => route('admin.reports.update', Report::factory()->create())],
    'payments' => ['get', fn () => route('admin.payments.index')],
    'payment refund' => ['post', fn () => route('admin.payments.refund', Payment::factory()->create())],
    'post' => ['get', fn () => route('admin.posts.show', Post::factory()->create())],
    'post update' => ['patch', fn () => route('admin.posts.update', Post::factory()->create())],
    'order refund' => ['post', fn () => route('admin.orders.refund', Order::factory()->paid()->create())],
    'product' => ['get', fn () => route('admin.products.show', Product::factory()->create())],
    'product update' => ['put', fn () => route('admin.products.update', Product::factory()->create())],
    'users' => ['get', fn () => route('admin.users.index')],
    'user update' => ['patch', fn () => route('admin.users.update', User::factory()->create())],
]);

it('returns 404 to non-admins on every admin route', function (string $method, Closure $url) {
    $this->actingAs(User::factory()->create())->{$method}($url())->assertNotFound();
})->with('admin routes');

it('redirects guests to login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('shares is_admin with the frontend', function () {
    $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', true));

    $this->actingAs(User::factory()->create())->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', false));
});

it('shows the dashboard numbers', function () {
    Report::factory()->count(2)->create();
    Report::factory()->create(['status' => ReportStatus::Resolved]);

    $ended = Support::factory()->ended()->create();
    Payment::factory()->for($ended)->create(['amount' => 1000, 'paid_at' => now()->subDays(5)]);
    Payment::factory()->for($ended)->create(['amount' => 500, 'paid_at' => now()->subDays(40), 'refunded_amount' => 500, 'refunded_at' => now()->subDays(2)]);
    Payment::factory()->for($ended)->create(['amount' => 700, 'paid_at' => now()->subDays(60), 'refunded_amount' => 700, 'refunded_at' => now()->subDays(45)]);

    Support::factory()->active()->count(3)->create();
    Support::factory()->ended()->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/dashboard')
            ->where('openReports', 2)
            ->where('sales30d', 1000)
            ->where('refunds30d', 500)
            ->where('activeSupports', 3));
});
