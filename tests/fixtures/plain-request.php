<?php

/**
 * A plain PHP page serving one request, run as its own process by PlainPhpTest: $_SERVER as a
 * web server sets it, Zharp::init() with no framework, then (by the mode in argv[2]) a page that
 * answers, one that sets a 404, or one whose exception escapes. What is exported is written to
 * the file in argv[1], one JSON object a line, as it is exported.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;

[, $out, $mode] = $argv;

/** Writes each exported span or log record to the file as soon as the exporter stores it. */
final class WriteThrough extends ArrayObject
{
    public function __construct(private readonly string $file)
    {
        parent::__construct();
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        parent::offsetSet($key, $value);
        $line = match (true) {
            $value instanceof SpanDataInterface => [
                'span' => $value->getName(),
                'kind' => $value->getKind(),
                'status' => $value->getStatus()->getCode(),
                'trace_id' => $value->getTraceId(),
                'parent' => $value->getParentSpanId(),
                'attributes' => $value->getAttributes()->toArray(),
            ],
            $value instanceof ReadableLogRecord => [
                'log' => $value->getBody(),
                'trace_id' => $value->getSpanContext()?->getTraceId(),
                'attributes' => $value->getAttributes()->toArray(),
            ],
            default => ['other' => get_debug_type($value)],
        };
        file_put_contents($this->file, json_encode($line) . "\n", FILE_APPEND);
    }
}

$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/checkout.php?coupon=SPRING&token=abc',
    'QUERY_STRING' => 'coupon=SPRING&token=abc',
    'SCRIPT_NAME' => '/checkout.php',
    'HTTP_HOST' => 'shop.test:8080',
    'HTTP_USER_AGENT' => 'curl/8.5.0',
    'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
    'HTTP_BAGGAGE' => 'enduser.id=u-42,session.id=s-1',
    'REMOTE_ADDR' => '203.0.113.9',
] + $_SERVER;

Zharp\Zharp::init([
    'key' => 'zpk_test',
    'service' => 'plain-php',
    'trace_request' => true,
    'span_exporter' => new InMemorySpans(new WriteThrough($out)),
    'log_exporter' => new InMemoryLogs(new WriteThrough($out)),
]);

function charge(int $cents): void
{
    if ($cents > 1000) {
        throw new RuntimeException('Card declined');
    }
}

register_shutdown_function(static function (): void {
    // The app's own shutdown work: still inside the request.
    Zharp\Zharp::info('shutdown work');
});

if ($mode === 'route') {
    Zharp\Zharp::setRoute('/checkout/{step}');
    echo "ok\n";
} elseif ($mode === 'not-found') {
    http_response_code(404);
    echo "not here\n";
} else {
    charge(5000);
}
