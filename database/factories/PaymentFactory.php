<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Support;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'support_id' => Support::factory(),
            'user_id' => fn (array $attributes) => Support::findOrFail((int) $attributes['support_id'])->user_id,
            'stripe_invoice_id' => 'in_fake_'.Str::random(14),
            'amount' => fn (array $attributes) => Support::findOrFail((int) $attributes['support_id'])->price,
            'paid_at' => now(),
        ];
    }
}
