<?php

// Zharp for Laravel. Everything here can stay as it is and be set in .env instead.
// Publish it with: php artisan vendor:publish --tag=zharp-config

return [
    // The app's key (zpk_…) from Zharp → Applications → your app → Setup. Without it nothing is sent.
    'key' => env('ZHARP_KEY'),

    // The service.name everything is filed under.
    'service' => env('ZHARP_SERVICE', env('APP_NAME')),

    'environment' => env('ZHARP_ENVIRONMENT', env('APP_ENV')),
    'release' => env('ZHARP_RELEASE'),
    'endpoint' => env('ZHARP_ENDPOINT'),

    // Share of traces kept, 0.0–1.0. Logs and errors are always sent.
    'sample_rate' => env('ZHARP_SAMPLE_RATE', 1.0),

    // false = logs only: logs, errors, user/session and tags are still sent, but no spans are
    // made or sent and no traceparent is added to outgoing requests.
    'tracing' => env('ZHARP_TRACING', true),

    // Request and response bodies on the request's span: false (default), 'errors' (requests that
    // answered 400 and above) or 'all'; or ['mode' => 'all', 'max_size' => 65536,
    // 'redact_keys' => ['date_of_birth'], 'urls' => ['/\/api\//']]. Field names are kept; values
    // under secret-looking keys (password, token, card, …) are sent as [Filtered]; uploaded files
    // are described (name, type, size), never sent. Text is capped at 32 KB (128 KB at most).
    'capture_bodies' => env('ZHARP_CAPTURE_BODIES', false),

    // Trace every HTTP request (a SERVER span each).
    'trace_requests' => env('ZHARP_TRACE_REQUESTS', true),

    // Send what Laravel logs, from this level up; false to send none.
    'log_level' => env('ZHARP_LOG_LEVEL', 'info'),
];
