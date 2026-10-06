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

];
