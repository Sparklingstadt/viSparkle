<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\ReorderProductImagesRequest;
use App\Http\Requests\Creator\StoreProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    public function store(StoreProductImageRequest $request, Product $product, ImageSanitizer $sanitizer): RedirectResponse
    {
        $path = $sanitizer->storePublic($request->file('image'), "creators/{$product->creator_id}/products", 'image');

        $product->images()->create([
            'path' => $path,
            'sort_order' => ($product->images()->max('sort_order') ?? -1) + 1,
        ]);

        return back();
    }

    public function destroy(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        abort_unless($request->user()->can('update', $product) && $image->product_id === $product->id, 403);

        $image->delete();
        Storage::disk('public')->delete($image->path);

        return back();
    }

    public function reorder(ReorderProductImagesRequest $request, Product $product): RedirectResponse
    {
        foreach ($request->validated('ids') as $position => $id) {
            $product->images()->whereKey($id)->update(['sort_order' => $position]);
        }

        return back();
    }
}
