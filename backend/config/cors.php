<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173,http://localhost:3000,http://127.0.0.1:3000,http://localhost,http://127.0.0.1')))),

    // Local dev: any port on localhost / 127.0.0.1 (Vite port changes, Laragon, etc.)
    'allowed_origins_patterns' => in_array(env('APP_ENV'), ['local', 'testing'], true)
        ? [
            '#^https?://localhost(:\d+)?$#',
            '#^https?://127\.0\.0\.1(:\d+)?$#',
        ]
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Payslip-Pdf-Password'],

    'max_age' => 0,

    // Required for Sanctum SPA cookie auth.
    'supports_credentials' => true,

];
