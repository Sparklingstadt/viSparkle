<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Fanbox helpers
|--------------------------------------------------------------------------
*/

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Fake the private disk and make temporary URLs deterministic:
 * "https://signed.test/{path}?expires={timestamp}" plus "&cd=1" when a Content-Disposition override is requested.
 */
function fakePrivateDisk(): void
{
    Storage::fake('private');
    Storage::disk('private')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiration, array $options) => "https://signed.test/{$path}?expires={$expiration->getTimestamp()}"
            .(isset($options['ResponseContentDisposition']) ? '&cd=1' : '')
    );
}

/**
 * A JPEG carrying an EXIF (APP1) segment, as phones produce.
 */
function jpegWithExif(int $width = 3000, int $height = 2000, string $name = 'photo.jpg'): UploadedFile
{
    $gd = imagecreatetruecolor($width, $height);
    imagefilledrectangle($gd, 0, 0, $width, $height, imagecolorallocate($gd, 200, 120, 40));
    ob_start();
    imagejpeg($gd, null, 80);
    $jpeg = (string) ob_get_clean();

    // Big-endian TIFF header with an empty IFD0, wrapped in an APP1 "Exif" segment.
    $payload = "Exif\0\0"."MM\0\x2A\0\0\0\x08"."\0\0"."\0\0\0\0";
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return UploadedFile::fake()->createWithContent($name, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
}

function gifUpload(int $width = 1200, int $height = 900, string $name = 'anim.gif'): UploadedFile
{
    $gd = imagecreatetruecolor($width, $height);
    ob_start();
    imagegif($gd);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

/*
| Tiptap document builders
*/

function ttText(string $value, array $marks = []): array
{
    return array_filter(['type' => 'text', 'text' => $value, 'marks' => $marks]);
}

function ttDoc(array ...$nodes): array
{
    return ['type' => 'doc', 'content' => $nodes];
}

function ttParagraph(array ...$inline): array
{
    return ['type' => 'paragraph', 'content' => $inline];
}

function ttImage(int $fileId): array
{
    return ['type' => 'image', 'attrs' => ['fileId' => $fileId]];
}
