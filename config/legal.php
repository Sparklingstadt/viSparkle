<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 特定商取引法に基づく表記
    |--------------------------------------------------------------------------
    |
    | The operator's details shown on /legal/tokushoho. A sole proprietor may leave the address and
    | phone empty and set LEGAL_DISCLOSE_ON_REQUEST=true to show "disclosed on request" instead.
    |
    */

    'seller_name' => env('LEGAL_SELLER_NAME'),
    'responsible_person' => env('LEGAL_RESPONSIBLE_PERSON'),
    'address' => env('LEGAL_ADDRESS'),
    'phone' => env('LEGAL_PHONE'),
    'email' => env('LEGAL_EMAIL'),
    'disclose_on_request' => (bool) env('LEGAL_DISCLOSE_ON_REQUEST', false),

];
