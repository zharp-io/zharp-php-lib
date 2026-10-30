<?php

// Plain PHP: a span, logs, a handled error, then an uncaught exception. Key from the environment:
//
//     ZHARP_KEY=zpk_… ZHARP_ENDPOINT=http://localhost:3000/api/v1/otlp php examples/basic.php

require __DIR__ . '/../vendor/autoload.php';

use Zharp\Zharp;

Zharp::init(['service' => 'php-example', 'environment' => 'development']);

Zharp::startSpan('checkout', static function (): void {
    Zharp::info('Checkout opened', ['cart_items' => 3]);
    try {
        throw new RuntimeException('Card declined');
    } catch (RuntimeException $e) {
        Zharp::captureError($e, ['order_id' => 99]);
    }
});

throw new LogicException('Uncaught at the top level'); // reported (unhandled), then PHP ends as usual
