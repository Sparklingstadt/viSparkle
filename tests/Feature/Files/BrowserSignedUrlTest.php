<?php

use Illuminate\Support\Facades\Storage;

function s3TestConfig(array $overrides = []): array
{
    return [
        'driver' => 's3',
        'key' => 'key',
        'secret' => 'secret',
        'region' => 'us-east-1',
        'bucket' => 'fanbox-private',
        'endpoint' => 'http://rustfs:9000',
        'use_path_style_endpoint' => true,
        ...$overrides,
    ];
}

it('presigns against the browser-facing endpoint when configured', function () {
    $url = Storage::build(s3TestConfig(['signing_endpoint' => 'http://localhost:9000']))
        ->temporaryUrl('a/b.jpg', now()->addMinutes(5));

    expect($url)->toStartWith('http://localhost:9000/fanbox-private/a/b.jpg?')
        ->toContain('X-Amz-Signature=')
        // The SDK takes the expiry (whole seconds) less the clock at signing, so a second ticking over in between gives 299.
        ->toMatch('/X-Amz-Expires=(299|300)(&|$)/');
});

it('presigns against the regular endpoint otherwise', function () {
    $url = Storage::build(s3TestConfig())->temporaryUrl('a/b.jpg', now()->addMinutes(5));

    expect($url)->toStartWith('http://rustfs:9000/fanbox-private/a/b.jpg?');
});

it('produces a different signature than host-rewriting would', function () {
    $signed = Storage::build(s3TestConfig(['signing_endpoint' => 'http://localhost:9000']))
        ->temporaryUrl('a/b.jpg', now()->addMinutes(5));
    $rewritten = Storage::build(s3TestConfig(['temporary_url' => 'http://localhost:9000']))
        ->temporaryUrl('a/b.jpg', now()->addMinutes(5));

    parse_str(parse_url($signed, PHP_URL_QUERY), $a);
    parse_str(parse_url($rewritten, PHP_URL_QUERY), $b);

    expect($a['X-Amz-Signature'])->not->toBe($b['X-Amz-Signature']);
});
