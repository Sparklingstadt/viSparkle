<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes uploads to the private disk as they are, streamed rather than read into memory (post attachments, work files).
 */
class PrivateFileWriter
{
    /**
     * Write the upload to "{$base}.{extension}". The extension is the client's, lowercased, or `bin` when it is unusual.
     *
     * @return array{path: string, mime: string, size: int}
     *
     * @throws RuntimeException when the upload cannot be read or written (nothing is left on the disk)
     */
    public function writeUpload(string $base, UploadedFile $upload): array
    {
        $extension = Str::lower($upload->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'bin';
        $path = "{$base}.{$extension}";

        $stream = fopen($upload->getRealPath(), 'r');

        if ($stream === false) {
            throw new RuntimeException("Failed to open the uploaded file for [{$path}].");
        }

        try {
            if (Storage::disk('private')->put($path, $stream) === false) {
                throw new RuntimeException("Failed to write [{$path}] to private storage.");
            }
        } catch (RuntimeException $e) {
            rescue(fn () => Storage::disk('private')->delete($path));

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'path' => $path,
            'mime' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $upload->getSize(),
        ];
    }
}
