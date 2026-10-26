<?php

// Zharp for Laravel. Everything here can stay as it is and be set in .env instead.
// Publish this file with: php artisan vendor:publish --tag=zharp-config

return [
    // An Ingest only key from Zharp → Integrations & API → Main API keys.
    'api_key' => env('ZHARP_API_KEY'),

    // The name your logs are filed under in Zharp.
    'service' => env('ZHARP_SERVICE', env('APP_NAME')),

    // Tags every log carries.
    'environment' => env('ZHARP_ENV', env('APP_ENV')),
    'app_version' => env('ZHARP_VERSION'),
    'source' => env('ZHARP_SOURCE'),

    'endpoint' => env('ZHARP_ENDPOINT'),

    // The lowest level the "zharp" log channel sends.
    'level' => env('ZHARP_LEVEL', 'debug'),
];
