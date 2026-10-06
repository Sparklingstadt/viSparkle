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
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;
use Throwable;

/**
 * Stores post uploads on the private disk.
 *
 * Images (except GIF) are decoded and re-encoded, which drops EXIF and other
 * metadata such as GPS coordinates, and scaled to a display size plus a thumbnail.
 * GIFs are kept byte-for-byte to preserve animation; their thumbnail is the first frame.
 */
class PostFileStorer
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

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
        $mime = (string) $upload->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? throw new RuntimeException("Unsupported image type [{$mime}].");
        $path = "{$base}.{$extension}";
        $thumbPath = "{$base}_thumb.{$extension}";

        $image = $this->images()->decodePath($upload->getRealPath());

        if ($mime === 'image/gif') {
            $contents = (string) file_get_contents($upload->getRealPath());
            $width = $image->width();
            $height = $image->height();
        } else {
            $image->scaleDown(config('fanbox.image_max_edge'), config('fanbox.image_max_edge'));
            $contents = (string) $image->encode($this->encoderFor($mime));
            $width = $image->width();
            $height = $image->height();
        }

        $this->put($disk, $path, $contents, $written);
        $this->put($disk, $thumbPath, $this->thumbnail($image, $mime), $written);

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

    private function thumbnail(ImageInterface $image, string $mime): string
    {
        if ($image->isAnimated()) {
            $image->removeAnimation(0);
        }

        $image->scaleDown(config('fanbox.thumb_max_edge'), config('fanbox.thumb_max_edge'));

        return (string) $image->encode($this->encoderFor($mime));
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

    private function encoderFor(string $mime): EncoderInterface
    {
        return match ($mime) {
            'image/png' => new PngEncoder(strip: true),
            'image/webp' => new WebpEncoder(quality: 85, strip: true),
            'image/gif' => new GifEncoder,
            default => new JpegEncoder(quality: 85, strip: true),
        };
    }

    private function images(): ImageManager
    {
        return new ImageManager(Driver::class, autoOrientation: true, strip: true);
    }
}
