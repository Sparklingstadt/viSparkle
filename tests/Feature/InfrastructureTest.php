<?php

it('configures private and public disks on s3', function () {
    expect(config('filesystems.disks.private.driver'))->toBe('s3')
        ->and(config('filesystems.disks.public.driver'))->toBe('s3')
        ->and(config('filesystems.disks.public.visibility'))->toBe('public');
});

it('exposes fanbox limits', function () {
    expect(config('fanbox.image_max_kb'))->toBe(10240)
        ->and(config('fanbox.attachment_max_kb'))->toBe(102400)
        ->and(config('fanbox.attachments_per_post'))->toBe(20)
        ->and(config('fanbox.images_per_post'))->toBe(100)
        ->and(config('fanbox.signed_url_minutes'))->toBe(5)
        ->and(config('fanbox.plan_min_price'))->toBe(100)
        ->and(config('fanbox.plan_max_price'))->toBe(50000)
        ->and(config('fanbox.plans_per_creator'))->toBe(10)
        ->and(config('fanbox.reserved_slugs'))->toContain('admin', 'feed', 'creator');
});

it('uses japanese locale and tokyo timezone', function () {
    expect(app()->getLocale())->toBe('ja')
        ->and(config('app.timezone'))->toBe('Asia/Tokyo');
});
