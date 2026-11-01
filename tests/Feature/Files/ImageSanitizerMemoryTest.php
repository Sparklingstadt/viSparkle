<?php

use App\Services\ImageSanitizer;

it('keeps the configured pixel cap when memory is unlimited', function () {
    expect(app(ImageSanitizer::class)->maxPixels('-1'))->toBe(config('fanbox.image_max_pixels'));
});

it('lowers the pixel cap to what fits in a 256M memory limit', function () {
    $cap = app(ImageSanitizer::class)->maxPixels('256M');

    // An 8K frame (7680x4320, ~33 MP) exhausted 256M in production; it must be refused before decoding.
    expect($cap)->toBeLessThan(7680 * 4320)
        ->and($cap)->toBeGreaterThan(12_000_000);
});

it('never exceeds the configured cap on large memory limits', function () {
    expect(app(ImageSanitizer::class)->maxPixels('8G'))->toBe(config('fanbox.image_max_pixels'));
});
