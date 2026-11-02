<?php

use App\Services\PrivateFileUrls;
use Illuminate\Support\Facades\Storage;

it('signs a download with the encoded original name', function () {
    Storage::fake('private');
    Storage::disk('private')->buildTemporaryUrlsUsing(fn ($path, $exp, array $options) => 'https://signed.test/'.$path.'#'.($options['ResponseContentDisposition'] ?? ''));

    expect(app(PrivateFileUrls::class)->download('a/b.zip', '画集 vol.1.zip'))
        ->toBe("https://signed.test/a/b.zip#attachment; filename*=UTF-8''".rawurlencode('画集 vol.1.zip'));
});

it('signs a plain url without a disposition', function () {
    Storage::fake('private');
    Storage::disk('private')->buildTemporaryUrlsUsing(fn ($path, $exp, array $options) => 'https://signed.test/'.$path.'#'.($options['ResponseContentDisposition'] ?? ''));

    expect(app(PrivateFileUrls::class)->sign('a/b.jpg'))->toBe('https://signed.test/a/b.jpg#');
});
