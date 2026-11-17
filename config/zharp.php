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

    // Share of traces kept, 0.0–1.0. Errors (and logs, when on) are always sent.
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

    // Every line Laravel's log channels write during a request ends with
    // " trace_id=<32 hex> span_id=<16 hex>" (a JSON line gets trace_id and span_id fields), so
    // Zharp links the line the Zharp agent collects to its request. In containers, log to stderr
    // (LOG_CHANNEL=stderr) and the agent brings the lines in. false to leave lines as they are.
    'log_correlation' => env('ZHARP_LOG_CORRELATION', true),

    // Send what the app logs from the SDK too. Off by default: where the Zharp agent reads the
    // app's logs it already brings them in, and sending them here as well would send every line
    // twice. Turn it on where there is no agent (serverless, managed hosts). Errors, traces and
    // Zharp::log() calls are sent either way.
    'logs' => env('ZHARP_LOGS', false),

    // With logs on: send what Laravel logs from this level up; false to send none.
    'log_level' => env('ZHARP_LOG_LEVEL', 'info'),

    // With logs on: in queue workers, artisan commands and Octane/Swoole/RoadRunner workers, also
    // send what the process prints (echo, print, var_dump, a command's output), each line with the
    // trace it was printed in and its level read from the line. Never the HTTP response, and never
    // a line Laravel's log already sent. false to turn it off.
    'capture_output' => env('ZHARP_CAPTURE_OUTPUT', true),
];
