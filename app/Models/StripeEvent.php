<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A received Stripe webhook event, kept so a replayed event id is acknowledged without being processed again.
 *
 * @property string $id
 * @property string $type
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['id', 'type', 'processed_at'])]
class StripeEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
