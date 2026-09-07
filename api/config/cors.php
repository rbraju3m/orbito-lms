<?php

declare(strict_types=1);

/*
| The SPA calls the API cross-origin with credentials, so the allowed origins
| must be explicit — `*` is illegal together with credentials, and would be
| wrong here anyway. FRONTEND_URL is a comma-separated list.
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')),
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Authorization', 'Content-Type', 'X-Requested-With',
        'X-XSRF-TOKEN', 'X-Request-Id', 'Idempotency-Key', 'Accept-Language',
    ],

    'exposed_headers' => [
        'X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,
];
