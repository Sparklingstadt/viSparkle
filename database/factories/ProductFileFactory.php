<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductFile>
 */
class ProductFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->digital(),
            'path' => 'creators/0/products/0/'.Str::lower((string) Str::ulid()).'.zip',
            'original_name' => 'files.zip',
            'mime' => 'application/zip',
            'size' => 2048,
            'sort_order' => 0,
        ];
    }
}
