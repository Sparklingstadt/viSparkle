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

/**
 * @property int $id
 * @property int $creator_id
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property string|null $cover_path
 * @property bool $is_accepting
 * @property int $sort
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
            'price' => 'integer',
            'is_accepting' => 'boolean',
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
