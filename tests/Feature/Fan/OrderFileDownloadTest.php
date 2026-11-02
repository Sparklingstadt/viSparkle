<?php

use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;

beforeEach(function () {
    fakePrivateDisk();
    $this->buyer = User::factory()->create();
    $this->creator = Creator::factory()->payable()->create();
    $this->work = Product::factory()->digital()->for($this->creator)->create(['price' => 800]);
    $this->file = ProductFile::factory()->for($this->work)->create(['original_name' => '画集 vol.1.zip']);
    $this->order = Order::factory()->paid()->for($this->buyer)->for($this->creator)->withItem($this->work)->create();
});

it('redirects the buyer to a signed download url', function () {
    $response = $this->actingAs($this->buyer)->get(route('orders.files.download', [$this->order, $this->file]))->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith("https://signed.test/{$this->file->path}")->toContain('&cd=1');
});

it('returns 404 for someone else\'s order', function () {
    $this->actingAs(User::factory()->create())->get(route('orders.files.download', [$this->order, $this->file]))->assertNotFound();
});

it('returns 404 for a file of another work', function () {
    $other = Product::factory()->digital()->for($this->creator)->create();
    $otherFile = ProductFile::factory()->for($other)->create();
    $theirs = ProductFile::factory()->create();

    $this->actingAs($this->buyer)->get(route('orders.files.download', [$this->order, $otherFile]))->assertNotFound();
    $this->actingAs($this->buyer)->get(route('orders.files.download', [$this->order, $theirs]))->assertNotFound();
});

it('returns 404 when access is refused', function (Closure $refuse) {
    $refuse($this->order, $this->work);

    $this->actingAs($this->buyer)->get(route('orders.files.download', [$this->order, $this->file]))->assertNotFound();
})->with([
    'pending' => fn (Order $order) => $order->forceFill(['status' => 'pending', 'paid_at' => null])->save(),
    'refunded' => fn (Order $order) => $order->forceFill(['status' => 'refunded', 'refunded_amount' => $order->total])->save(),
    'unpublished' => fn (Order $order, Product $work) => $work->update(['is_published' => false]),
]);

it('requires login', function () {
    $this->get(route('orders.files.download', [$this->order, $this->file]))->assertRedirect(route('login'));
});
