<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $support_id
 * @property int|null $user_id
 * @property int $creator_id
 * @property string $stripe_invoice_id
 * @property int $amount
 * @property int $fee_percent
 * @property Carbon $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['support_id', 'user_id', 'creator_id', 'stripe_invoice_id', 'amount', 'fee_percent', 'paid_at'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee_percent' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return BelongsTo<Support, $this> */
    public function support(): BelongsTo
    {
        return $this->belongsTo(Support::class);
    }
}
