<?php

// A CLI process for OutputCaptureTest: prints in every way a worker might, then writes what Zharp
// sent to the JSON file named by its first argument. The second argument: "all" (default), or
// "off" for capture_output => false (ZHARP_CAPTURE_OUTPUT is read from the environment).

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use Zharp\Internal\OutputCapture;
use Zharp\Monolog\ZharpHandler;
use Zharp\Zharp;

$file = $argv[1];
$mode = $argv[2] ?? 'all';
$logs = new ArrayObject();
$traceId = null;
$previousCalls = 0;

// The app's own error handler, set first: it must still run.
set_error_handler(static function () use (&$previousCalls): bool {
    $previousCalls++;

    return false;
});

Zharp::init(array_filter([
    'key' => 'zpk_test',
    'service' => 'output-child',
    'span_exporter' => new InMemorySpans(new ArrayObject()),
    'log_exporter' => new InMemoryLogs($logs),
    'capture_output' => $mode === 'off' ? false : null,
], static fn ($v) => $v !== null));

// Registered after Zharp's own, so it runs once Zharp has sent everything.
register_shutdown_function(static function () use ($file, $logs, &$traceId, &$previousCalls): void {
    $records = [];
    foreach ($logs as $record) {
        $records[] = [
            'body' => $record->getBody(),
            'severity' => $record->getSeverityNumber(),
            'text' => $record->getSeverityText(),
            'trace_id' => $record->getSpanContext()?->isValid() ? $record->getSpanContext()->getTraceId() : null,
            'attributes' => $record->getAttributes()->toArray(),
        ];
    }
    file_put_contents($file, json_encode([
        'records' => $records,
        'trace_id' => $traceId,
        'previous_calls' => $previousCalls,
    ]));
});

ini_set('display_errors', '1');
ini_set('html_errors', '0');
ini_set('log_errors', '0');

echo "plain stdout line\n";
echo "[warn] disk almost full\n";
echo "level=error msg=\"payment failed\"\n";
echo '{"level":"error","message":"json says error"}', "\n";
echo "[2026-11-17 08:33:00] production.CRITICAL: monolog formatted line [] []\n";
echo "\033[31mERROR\033[0m coloured\n";
printf('split %s', 'across ');
echo "two writes\n";

Zharp::startSpan('job', static function () use (&$traceId): void {
    $traceId = Span::getCurrent()->getContext()->getTraceId();
    echo "inside the span\n";
});

// Monolog printing to the output and sending to Zharp: one record, whichever handler runs first.
$printsLast = new Logger('app');
$printsLast->pushHandler(new StreamHandler('php://output'));
$printsLast->pushHandler(new ZharpHandler('debug'));
$printsLast->warning('monolog once');
$printsFirst = new Logger('app');
$printsFirst->pushHandler(new ZharpHandler('debug'));
$printsFirst->pushHandler(new StreamHandler('php://output'));
$printsFirst->error('monolog printed first');

// A warning PHP shows on stdout: sent once, by the error handler; silenced ones not at all.
trigger_error('careful now', E_USER_WARNING);
@trigger_error('silenced', E_USER_WARNING);

// A console command's streams, as Symfony Console opens them.
$stdout = fopen('php://stdout', 'w');
$stderr = fopen('php://stderr', 'w');
$errorOutput = new class ($stderr) {
    public function __construct(private $stream)
    {
    }

    public function getStream()
    {
        return $this->stream;
    }
};
$console = new class ($stdout, $errorOutput) {
    public function __construct(private $stream, private object $error)
    {
    }

    public function getStream()
    {
        return $this->stream;
    }

    public function getErrorOutput(): object
    {
        return $this->error;
    }
};
OutputCapture::tapConsole($console);
fwrite($stdout, "console says hi\n");
fwrite($stderr, "console stderr line\n");
fwrite($stderr, "ERROR console failure\n");

echo "[Zharp] the SDK talking about itself\n";
echo "no newline at the end";
