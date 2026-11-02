<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ProductKind;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $creator_id
 * @property ProductKind $kind
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property bool $is_published
 * @property int $sort_order
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $stock the total of the variants' stock
 */
#[Fillable(['name', 'description', 'price', 'is_published', 'sort_order'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'price' => 'integer',
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

    /**
     * Best sellers first: units in orders that were paid (refunded and canceled ones don't count).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function popular(Builder $query): void
    {
        $query->withSum([
            'orderItems as sold_count' => fn ($q) => $q->whereHas('order', fn ($order) => $order
                ->whereIn('status', [OrderStatus::Paid, OrderStatus::Shipped])),
        ], 'quantity')
            ->orderByDesc('sold_count')
            ->orderByDesc('id');
    }

    public function isListed(): bool
    {
        return $this->is_published && $this->hidden_at === null && ! $this->trashed();
    }

    public function isDigital(): bool
    {
        return $this->kind === ProductKind::Digital;
    }

    /**
     * Listed and on offer: goods need stock, a digital work needs at least one file (it holds no stock).
     */
    public function isPurchasable(): bool
    {
        if (! $this->isListed()) {
            return false;
        }

        if ($this->isDigital()) {
            return $this->relationLoaded('files') ? $this->files->isNotEmpty() : $this->files()->exists();
        }

        return $this->stock > 0;
    }

    /**
     * The total stock of the variants, for lists that show a product as sold out.
     *
     * @return Attribute<int, never>
     */
    protected function stock(): Attribute
    {
        return Attribute::get(fn () => (int) $this->variants->sum('stock'));
    }

    public function hasKinds(): bool
    {
        return $this->variants->count() > 1;
    }

    public function coverUrl(): ?string
    {
        return $this->images->first()?->url();
    }

    /**
     * @return array{id: int, name: string, price: int, sold_out: bool, cover_url: string|null}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'sold_out' => $this->stock < 1,
            'cover_url' => $this->coverUrl(),
        ];
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ProductFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
