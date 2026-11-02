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

    public function withItem(Product $product, int $quantity = 1): static
    {
        return $this->afterCreating(function (Order $order) use ($product, $quantity) {
            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit_price' => $product->price,
                'quantity' => $quantity,
            ]);
        });
    }
}
