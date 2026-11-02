<?php

namespace App\Models;

use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $creator_id
 * @property OrderStatus $status
 * @property int $subtotal
 * @property int $shipping_fee
 * @property int $total
 * @property int $fee_percent
 * @property string|null $shipping_name
 * @property string|null $shipping_postal_code
 * @property string|null $shipping_address
 * @property string|null $shipping_phone
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_payment_intent_id
 * @property string|null $tracking_number
 * @property Carbon|null $paid_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $refunded_at
 * @property Carbon|null $disputed_at
 * @property int $refunded_amount
 * @property OrderCancelReason|null $cancel_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'creator_id', 'status', 'subtotal', 'shipping_fee', 'total', 'fee_percent'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'integer',
            'shipping_fee' => 'integer',
            'total' => 'integer',
            'fee_percent' => 'integer',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'canceled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'disputed_at' => 'datetime',
            'refunded_amount' => 'integer',
            'cancel_reason' => OrderCancelReason::class,
        ];
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

    public function hasPhysical(): bool
    {
        return $this->kindItems()->contains(fn (OrderItem $item) => ! $item->isDigital());
    }

    public function hasDigital(): bool
    {
        return $this->kindItems()->contains(fn (OrderItem $item) => $item->isDigital());
    }

    /**
     * The items, from the loaded relation when there is one.
     *
     * @return Collection<int, OrderItem>
     */
    private function kindItems(): Collection
    {
        return $this->relationLoaded('items') ? $this->items : $this->items()->get();
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
