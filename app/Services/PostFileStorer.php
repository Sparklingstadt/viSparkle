<?php

namespace App\Services;

use App\Enums\PostFileKind;
use App\Models\Post;
use App\Models\PostFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores post uploads on the private disk.
 *
 * Images (except GIF) are re-encoded via ImageSanitizer, which drops EXIF and other
 * metadata such as GPS coordinates, and scaled to a display size plus a thumbnail.
 * GIFs are kept byte-for-byte to preserve animation; their thumbnail is the first frame.
 */
class PostFileStorer
{
    public function __construct(private readonly ImageSanitizer $images) {}

    public function store(Post $post, UploadedFile $upload, PostFileKind $kind): PostFile
    {
        $disk = Storage::disk('private');
        $base = "creators/{$post->creator_id}/posts/{$post->id}/".Str::lower((string) Str::ulid());
        $written = [];

        try {
            $attributes = $kind === PostFileKind::Image
                ? $this->storeImage($disk, $base, $upload, $written)
                : $this->storeAttachment($disk, $base, $upload, $written);

            return DB::transaction(fn () => $post->files()->create([
                ...$attributes,
                'kind' => $kind,
                'original_name' => Str::limit($upload->getClientOriginalName(), 250, ''),
                'sort' => (int) $post->files()->where('kind', $kind)->max('sort') + 1,
            ]));
        } catch (Throwable $e) {
            if ($written !== []) {
                rescue(fn () => $disk->delete($written));
            }

            throw $e;
        }
    }

    public function delete(PostFile $file): void
    {
        $file->delete();

        rescue(fn () => Storage::disk('private')->delete(array_values(array_filter([$file->path, $file->thumb_path]))));
    }

    /**
     * @param  list<string>  $written
     * @return array<string, mixed>
     */
    private function storeImage(Filesystem $disk, string $base, UploadedFile $upload, array &$written): array
    {
        ['image' => $image, 'mime' => $mime, 'extension' => $extension] = $this->images->decode($upload, 'file');
        $path = "{$base}.{$extension}";
        $thumbPath = "{$base}_thumb.{$extension}";

        if ($mime === 'image/gif') {
            // Keep animated GIFs intact; only the first frame was decoded, for the thumbnail.
            $contents = (string) file_get_contents($upload->getRealPath());
        } else {
            $image->scaleDown(config('fanbox.image_max_edge'), config('fanbox.image_max_edge'));
            $contents = $this->images->encode($image, $mime);
        }

        $width = $image->width();
        $height = $image->height();

        $this->put($disk, $path, $contents, $written);

        $image->scaleDown(config('fanbox.thumb_max_edge'), config('fanbox.thumb_max_edge'));
        $this->put($disk, $thumbPath, $this->images->encode($image, $mime), $written);

        return [
            'path' => $path,
            'thumb_path' => $thumbPath,
            'mime' => $mime,
            'size' => strlen($contents),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param  list<string>  $written
     * @return array<string, mixed>
     */
    private function storeAttachment(Filesystem $disk, string $base, UploadedFile $upload, array &$written): array
    {
        $extension = Str::lower($upload->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'bin';
        $path = "{$base}.{$extension}";

        $stream = fopen($upload->getRealPath(), 'r');

        if ($stream === false) {
            throw new RuntimeException("Failed to open the uploaded file for [{$path}].");
        }

        try {
            $this->put($disk, $path, $stream, $written);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'path' => $path,
            'thumb_path' => null,
            'mime' => $upload->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $upload->getSize(),
            'width' => null,
            'height' => null,
        ];
    }

    /**
     * @param  string|resource  $contents
     * @param  list<string>  $written
     */
    private function put(Filesystem $disk, string $path, mixed $contents, array &$written): void
    {
        if ($disk->put($path, $contents) === false) {
            throw new RuntimeException("Failed to write [{$path}] to private storage.");
        }

        $written[] = $path;
    }
}
