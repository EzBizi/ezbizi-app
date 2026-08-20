<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | The SPA is same-origin with the API (app.ezbizi.com and dev.ezbizi.com
    | both serve UI and /api). CORS is therefore unnecessary in production and
    | must not be `*` — that would let any page that obtained a bearer token
    | use it from a browser.
    |
    | Localhost is allowed by pattern so `flutter run -d chrome` against a
    | local API (any port) still works. Production hosts are an exact list.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'https://app.ezbizi.com,https://dev.ezbizi.com',
        )),
    ))),

    'allowed_origins_patterns' => [
        '#^https?://localhost(:[0-9]+)?$#',
        '#^https?://127\.0\.0\.1(:[0-9]+)?$#',
        '#^https?://\[::1\](:[0-9]+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
