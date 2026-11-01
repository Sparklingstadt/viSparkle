<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Safe decoding and re-encoding of user-uploaded images.
 *
 * Dimensions are checked from the header before decoding (GD allocates
 * width x height x 4 bytes), decode failures become validation errors, and
 * re-encoding drops EXIF and other metadata such as GPS coordinates.
 */
class ImageSanitizer
{
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Validate the header and decode the image (only the first frame of animations).
     *
     * @return array{image: ImageInterface, mime: string, extension: string}
     *
     * @throws ValidationException
     */
    public function decode(UploadedFile $upload, string $field): array
    {
        $mime = (string) $upload->getMimeType();
        $extension = self::EXTENSIONS[$mime] ?? $this->fail($field, '対応していない画像形式です。');
        $size = @getimagesize($upload->getRealPath());

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            $this->fail($field, '画像を読み込めませんでした。');
        }

        $maxPixels = $this->maxPixels();

        if ($maxPixels < $size[0] * $size[1]) {
            $this->fail($field, '画像の解像度が大きすぎます（'.number_format(intdiv($maxPixels, 1_000_000)).'メガピクセルまで）。');
        }

        try {
            $image = (new ImageManager(Driver::class, autoOrientation: true, decodeAnimation: false, strip: true))
                ->decodePath($upload->getRealPath());
        } catch (Throwable) {
            $this->fail($field, '画像を読み込めませんでした。');
        }

        return ['image' => $image, 'mime' => $mime, 'extension' => $extension];
    }

    public function encode(ImageInterface $image, string $mime): string
    {
        return (string) $image->encode($this->encoderFor($mime));
    }

    /**
     * Re-encode a public image (avatar, header, plan cover) and store it on the public disk.
     *
     * @throws ValidationException
     */
    public function storePublic(UploadedFile $upload, string $directory, string $field): string
    {
        ['image' => $image, 'mime' => $mime, 'extension' => $extension] = $this->decode($upload, $field);

        if ($mime === 'image/gif') {
            $mime = 'image/png';
            $extension = 'png';
        }

        $image->scaleDown(config('fanbox.image_max_edge'), config('fanbox.image_max_edge'));
        $path = "{$directory}/".Str::lower((string) Str::ulid()).".{$extension}";
        Storage::disk('public')->put($path, $this->encode($image, $mime));

        return $path;
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

    /**
     * The configured pixel cap, lowered to what fits in the remaining PHP memory: GD needs ~4 bytes per pixel for the
     * decoded bitmap plus working copies while orienting and resizing, so allow about 10 bytes per pixel.
     */
    public function maxPixels(?string $memoryLimit = null): int
    {
        $configured = (int) config('fanbox.image_max_pixels');
        $limit = $this->bytes($memoryLimit ?? (string) ini_get('memory_limit'));

        if ($limit <= 0) {
            return $configured;
        }

        return max(0, min($configured, intdiv($limit - memory_get_usage(), 10)));
    }

    /** Parse a php.ini size such as "256M"; -1 (unlimited) and unparsable values give 0. */
    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1' || ! preg_match('/^(\d+)\s*([KMG]?)$/i', $value, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
