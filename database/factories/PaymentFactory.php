<?php

namespace Database\Factories;

use App\Models\Creator;
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
            'creator_id' => fn (array $attributes) => Support::findOrFail((int) $attributes['support_id'])->creator_id,
            'stripe_invoice_id' => 'in_fake_'.Str::random(14),
            'amount' => fn (array $attributes) => Support::findOrFail((int) $attributes['support_id'])->price,
            'fee_percent' => (int) config('fanbox.billing.platform_fee_percent'),
            'paid_at' => now(),
        ];
    }

    public function forCreator(Creator $creator): static
    {
        return $this->state(fn () => ['creator_id' => $creator->id]);
    }
}
