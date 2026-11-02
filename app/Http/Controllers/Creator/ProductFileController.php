<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\ReorderProductFilesRequest;
use App\Http\Requests\Creator\StoreProductFileRequest;
use App\Models\Product;
use App\Models\ProductFile;
use App\Services\PrivateFileUrls;
use App\Services\Shop\ProductFileStorer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The files of a creator's digital work. Goods have no files (404).
 */
class ProductFileController extends Controller
{
    public function store(StoreProductFileRequest $request, Product $product, ProductFileStorer $storer): RedirectResponse
    {
        abort_unless($product->isDigital(), 404);

        $storer->store($product, $request->file('file'));

        return back();
    }

    public function reorder(ReorderProductFilesRequest $request, Product $product): RedirectResponse
    {
        abort_unless($product->isDigital(), 404);

        foreach ($request->validated('ids') as $position => $id) {
            $product->files()->whereKey($id)->update(['sort_order' => $position]);
        }

        return back();
    }

    public function destroy(Request $request, Product $product, ProductFile $file, ProductFileStorer $storer): RedirectResponse
    {
        $this->authorizeFile($request, $product, $file);

        // The count is read under the work's lock, so two deletions at once cannot empty a published work.
        DB::transaction(function () use ($product, $file, $storer) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->is_published && $product->files()->count() <= 1) {
                throw ValidationException::withMessages(['file' => '公開中の作品からすべてのファイルを消すことはできません。']);
            }

            $storer->delete($file);
        });

        return back();
    }

    /**
     * The creator may download their own files at any time, published or not.
     */
    public function download(Request $request, Product $product, ProductFile $file, PrivateFileUrls $urls): RedirectResponse
    {
        $this->authorizeFile($request, $product, $file);

        return redirect()->away($urls->download($file->path, $file->original_name));
    }

    private function authorizeFile(Request $request, Product $product, ProductFile $file): void
    {
        abort_unless($request->user()->can('update', $product), 403);
        abort_unless($product->isDigital() && $file->product_id === $product->id, 404);
    }
}
