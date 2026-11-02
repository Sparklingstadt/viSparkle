<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Short-lived signed URLs for files on the private disk. Callers check who may see the file first.
 */
class PrivateFileUrls
{
    /**
     * @param  array<string, string>  $options
     */
    public function sign(string $path, array $options = []): string
    {
        return Storage::disk('private')->temporaryUrl(
            $path,
            now()->addMinutes(config('fanbox.signed_url_minutes')),
            $options,
        );
    }

    /**
     * A URL that saves the file under its original name instead of opening it.
     */
    public function download(string $path, string $originalName): string
    {
        return $this->sign($path, ['ResponseContentDisposition' => "attachment; filename*=UTF-8''".rawurlencode($originalName)]);
    }
}
