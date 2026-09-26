<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |---------------------------------------------------------------------------
    |
    | The SPA is deployed separately from the API (Cloudflare Pages / Netlify
    | calling a Laravel backend), so every browser request is cross-origin.
    |
    | Origins are an explicit allow-list driven by FRONTEND_URL rather than '*'.
    | Authentication uses Sanctum bearer tokens, not cookies, so credentialed
    | requests are unnecessary and 'supports_credentials' stays false.
    |
    */

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
