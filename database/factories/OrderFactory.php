<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Creator;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'creator_id' => Creator::factory()->payable(),
            'status' => OrderStatus::Pending,
            'subtotal' => 1500,
            'shipping_fee' => 0,
            'total' => 1500,
            'fee_percent' => (int) config('fanbox.billing.platform_fee_percent'),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'stripe_payment_intent_id' => 'pi_fake_'.Str::random(10),
        ]);
    }

    public function shipped(): static
    {
        return $this->paid()->state(fn () => [
            'status' => OrderStatus::Shipped,
            'shipped_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Pending]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Canceled,
            'canceled_at' => now(),
        ]);
    }

    public function refunded(): static
    {
        return $this->paid()->state(fn (array $attributes) => [
            'status' => OrderStatus::Refunded,
            'refunded_at' => now(),
            'refunded_amount' => $attributes['total'] ?? 1500,
        ]);
    }

    public function withItem(Product $product, int $quantity = 1): static
    {
        return $this->afterCreating(function (Order $order) use ($product, $quantity) {
            $variant = $product->variants()->firstOrFail();
            $order->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name' => $variant->label(),
                'product_kind' => $product->kind,
                'unit_price' => $product->price,
                'quantity' => $quantity,
            ]);
        });
    }
}
