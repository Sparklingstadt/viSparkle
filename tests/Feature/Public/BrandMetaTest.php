<?php

it('serves the share card and icons in the page head', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<meta property="og:image" content="'.asset('images/og.png').'">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
});

it('ships the brand images', function (string $file) {
    expect(public_path($file))->toBeFile();
})->with(['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'icon-512.png', 'images/og.png']);

it('has a 1200x630 share image', function () {
    expect(getimagesize(public_path('images/og.png')))->toMatchArray([0 => 1200, 1 => 630]);
});
