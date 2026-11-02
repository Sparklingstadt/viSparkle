<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One kind of a product (a size, a design) with its own stock. A product without kinds has a single variant with no name.
 *
 * @property int $id
 * @property int $product_id
 * @property string|null $name
 * @property int $stock
 * @property int $sort_order
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'stock', 'sort_order'])]
class ProductVariant extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The product's name with the kind, as carts and orders show it: 「Tシャツ（M）」.
     */
    public function label(): string
    {
        return $this->name === null ? $this->product->name : "{$this->product->name}（{$this->name}）";
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
