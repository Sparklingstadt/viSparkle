<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */

    'image_max_kb' => 10240,
    'image_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'attachment_max_kb' => 102400,
    'attachments_per_post' => 20,
    'images_per_post' => 100,
    'image_max_edge' => 2560,
    'thumb_max_edge' => 640,
    // Decoding cost is width x height x 4 bytes; refuse anything larger before decoding.
    'image_max_pixels' => 50_000_000,
    'signed_url_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Plans & Posts
    |--------------------------------------------------------------------------
    */

    'plan_min_price' => 100,
    'plan_max_price' => 50000,
    'plans_per_creator' => 10,
    'body_max_bytes' => 524288,

    /*
    |--------------------------------------------------------------------------
    | Creator slugs
    |--------------------------------------------------------------------------
    */

    'reserved_slugs' => [
        'admin', 'api', 'creator', 'creators', 'login', 'logout', 'register',
        'feed', 'supporting', 'settings', 'dashboard', 'posts', 'plans',
        'storage', 'assets', 'help', 'terms', 'privacy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    */

    'billing' => [
        // stripe | fake. Production defaults to stripe so a build step without BILLING_DRIVER still boots;
        // choosing fake in production explicitly is refused by BillingServiceProvider.
        'driver' => env('BILLING_DRIVER', env('APP_ENV') === 'production' ? 'stripe' : 'fake'),
        'platform_fee_percent' => (int) env('BILLING_PLATFORM_FEE_PERCENT', 10),
        'stripe' => [
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        ],
        'checkout_expires_minutes' => 30,
        // Prefix for Stripe idempotency keys, so environments sharing one Stripe account (e.g. local and production on
        // the same test-mode keys) never reuse each other's keys. Defaults to "{APP_ENV}/{APP_URL host}".
        'idempotency_prefix' => env('BILLING_IDEMPOTENCY_PREFIX', env('APP_ENV', 'production').'/'.(parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'app')),
    ],

];
