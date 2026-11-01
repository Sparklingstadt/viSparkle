<?php

namespace App\Models;

use App\Enums\SupportStatus;
use Database\Factories\SupportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $creator_id
 * @property int|null $plan_id
 * @property int $price
 * @property SupportStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_status
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property string|null $current_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'creator_id', 'plan_id', 'price', 'status', 'started_at', 'ended_at'])]
class Support extends Model
{
    /** @use HasFactory<SupportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'status' => SupportStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    public function isBilling(): bool
    {
        return $this->stripe_subscription_id !== null;
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', SupportStatus::Active);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
