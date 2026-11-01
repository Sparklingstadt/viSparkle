<?php

namespace App\Models;

use App\Enums\SupportStatus;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $creator_id
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property string|null $cover_path
 * @property bool $is_accepting
 * @property int $sort
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'price', 'cover_path', 'is_accepting', 'sort'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'creator_id' => 'integer',
            'price' => 'integer',
            'is_accepting' => 'boolean',
        ];
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path === null ? null : Storage::disk('public')->url($this->cover_path);
    }

    /**
     * @return array{id: int, name: string, description: string|null, price: int, cover_url: string|null, is_accepting: bool, active_supporters_count: int}
     */
    public function toData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'cover_url' => $this->coverUrl(),
            'is_accepting' => $this->is_accepting,
            'active_supporters_count' => (int) ($this->active_supports_count ?? 0),
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return HasMany<Support, $this> */
    public function supports(): HasMany
    {
        return $this->hasMany(Support::class);
    }

    /** @return HasMany<Support, $this> */
    public function activeSupports(): HasMany
    {
        return $this->supports()->where('status', SupportStatus::Active);
    }
}
