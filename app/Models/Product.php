<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $creator_id
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property int $stock
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'description', 'price', 'stock', 'is_published', 'sort_order'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock' => 'integer',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function listed(Builder $query): void
    {
        $query->where('is_published', true)->whereNull('hidden_at');
    }

    public function isListed(): bool
    {
        return $this->is_published && $this->hidden_at === null && ! $this->trashed();
    }

    public function coverUrl(): ?string
    {
        return $this->images->first()?->url();
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
