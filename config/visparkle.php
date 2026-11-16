<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The creator
    |--------------------------------------------------------------------------
    |
    | The site has one creator, whose profile the home page shows. The account
    | that manages plans and posts is the user with `is_creator` set
    | (`php artisan visparkle:creator {email}`).
    |
    */

    'creator' => [
        'name' => env('CREATOR_NAME', 'viSparkle'),
        'bio' => env('CREATOR_BIO'),
        'avatar' => '/icon-512.png',
        'header' => '/images/og.png',
    ],

];
