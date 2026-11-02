<?php

namespace App\Services\Shop;

use App\Models\Product;
use App\Models\ProductFile;
use App\Services\PrivateFileWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores the files of digital works on the private disk, as they are (like post attachments).
 */
class ProductFileStorer
{
    public function __construct(private readonly PrivateFileWriter $writer) {}

    public function store(Product $product, UploadedFile $upload): ProductFile
    {
        $written = $this->writer->writeUpload(
            "creators/{$product->creator_id}/products/{$product->id}/".Str::lower((string) Str::ulid()),
            $upload,
        );

        try {
            return DB::transaction(fn () => $product->files()->create([
                ...$written,
                'original_name' => Str::limit($upload->getClientOriginalName(), 250, ''),
                'sort_order' => (int) $product->files()->max('sort_order') + 1,
            ]));
        } catch (Throwable $e) {
            rescue(fn () => Storage::disk('private')->delete($written['path']));

            throw $e;
        }
    }

    public function delete(ProductFile $file): void
    {
        $file->delete();

        rescue(fn () => Storage::disk('private')->delete($file->path));
    }
}
