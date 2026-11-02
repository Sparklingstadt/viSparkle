<?php

namespace App\Models;

use Database\Factories\ProductFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file of a digital work, on the private disk. Buyers download it through a short-lived signed URL.
 *
 * @property int $id
 * @property int $product_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['path', 'original_name', 'mime', 'size', 'sort_order'])]
class ProductFile extends Model
{
    /** @use HasFactory<ProductFileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
