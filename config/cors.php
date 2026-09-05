<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | The three consumers run on their own origins in local dev:
    |   admin panel  http://localhost:8080  (vite; 8081 if 8080 is taken)
    |   public web   http://localhost:5173  (vite default)
    |   Flutter      native, no origin, unaffected by CORS
    |
    | Auth is a bearer token, not a cookie, so credentials stay false.
    | Override the list with CORS_ALLOWED_ORIGINS (comma-separated) when a
    | frontend runs on a different port.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:8080,http://127.0.0.1:8080,http://localhost:8081,http://127.0.0.1:8081,http://localhost:5173,http://127.0.0.1:5173,https://scrapifyauctions.com,https://www.scrapifyauctions.com,https://admin.scrapifyauctions.com',
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
