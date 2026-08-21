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

    'paths' => ['api/*', 'docs/api.json'],

    'allowed_methods' => ['*'],

    /*
    | Native mobile HTTP clients (iOS/Android) are not subject to browser CORS
    | at all, so this setting only matters for browser-based tools calling the
    | API directly (Swagger/Postman-in-browser, a future web admin panel, or
    | fetching /docs/api.json into an external OpenAPI viewer). Defaults to a
    | wildcard for easy local/dev use; lock it down per-deployment via
    | CORS_ALLOWED_ORIGINS (comma-separated) without a code change.
    */
    'allowed_origins' => array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
