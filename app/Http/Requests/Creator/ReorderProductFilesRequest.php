<?php

namespace App\Http\Requests\Creator;

use App\Models\Product;

class ReorderProductFilesRequest extends ReorderProductItemsRequest
{
    protected function ownIds(Product $product): array
    {
        return array_map(intval(...), $product->files()->pluck('id')->all());
    }

    protected function mismatchMessage(): string
    {
        return 'ファイルの並び順が正しくありません。';
    }
}
