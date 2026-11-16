<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;

class ReorderProductImagesRequest extends ReorderProductItemsRequest
{
    protected function ownIds(Product $product): array
    {
        return array_map(intval(...), $product->images()->pluck('id')->all());
    }

    protected function mismatchMessage(): string
    {
        return '画像の並び順が正しくありません。';
    }
}
