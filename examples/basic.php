<?php

// Every kind of log, then an uncaught exception. Run with your ingest-only key in the environment:
//
//     ZHARP_API_KEY=your-ingest-only-key php examples/basic.php

require __DIR__ . '/../vendor/autoload.php';

use Zharp\Logs\Zharp;

Zharp::init(['service' => 'php-sample', 'environment' => 'development', 'app_version' => '1.0.0']);

Zharp::info('Checkout opened', ['cart_items' => 3, 'currency' => 'GHS']);
Zharp::warn('Slow network detected', ['latency_ms' => 3200]);

try {
    throw new RuntimeException('Card declined');
} catch (RuntimeException $e) {
    Zharp::error('Payment failed', ['order_id' => 99, 'exception' => $e]);
}

function charge(int $orderId): void
{
    throw new LogicException('Sample crash from PHP');
}

// Uncaught: sent as fatal with its stack, then PHP reports it and the script ends with code 255.
charge(99);
