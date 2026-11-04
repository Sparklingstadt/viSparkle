<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $product_id
 * @property int $user_id
 * @property Carbon|null $created_at
 */
class ProductLike extends Model
{
    public const UPDATED_AT = null;

    /**
     * Ids among $productIds that the viewer has liked, as a lookup set.
     *
     * @param  iterable<int>  $productIds
     * @return array<int, true>
     */
    public static function idsLikedBy(?User $viewer, iterable $productIds): array
    {
        $productIds = collect($productIds)->all();

        if ($viewer === null || $productIds === []) {
            return [];
        }

        $ids = static::query()
            ->where('user_id', $viewer->id)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
