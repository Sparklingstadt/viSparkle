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
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $creator_id
 * @property int|null $product_category_id
 * @property ProductKind $kind
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property bool $is_published
 * @property int $sort_order
 * @property int $like_count
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $stock the total of the variants' stock
 */
#[Fillable(['name', 'description', 'price', 'is_published', 'sort_order', 'product_category_id'])]
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
            'like_count' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function listed(Builder $query): void
    {
        $query->where('is_published', true)->whereNull('hidden_at');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function inCategory(Builder $query, ProductCategory $category): void
    {
        $query->where('product_category_id', $category->id);
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

    /**
     * Idempotent: like_count only moves when the row is actually added.
     */
    public function like(User $user): void
    {
        DB::transaction(function () use ($user) {
            $added = ProductLike::query()->insertOrIgnore([
                'product_id' => $this->id,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);

            if ($added > 0) {
                // Bypass Eloquent so a like doesn't bump updated_at.
                static::whereKey($this->id)->toBase()->increment('like_count');
            }
        });
    }

    /**
     * Idempotent: like_count only moves when a row is actually removed.
     */
    public function unlike(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($this->likes()->where('user_id', $user->id)->delete() > 0) {
                static::whereKey($this->id)->toBase()->decrement('like_count');
            }
        });
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
     * @return array{id: int, kind: string, name: string, price: int, sold_out: bool, cover_url: string|null}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'price' => $this->price,
            // Only goods sell out; a digital work holds no stock.
            'sold_out' => ! $this->isDigital() && $this->stock < 1,
            'cover_url' => $this->coverUrl(),
            'like_count' => $this->like_count,
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * The category, unless it is archived or unset: archived categories are not shown publicly.
     */
    public function publicCategory(): ?ProductCategory
    {
        $category = $this->category;

        return $category !== null && ! $category->isArchived() ? $category : null;
    }

    /** @return HasMany<ProductLike, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(ProductLike::class);
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
