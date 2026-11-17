<?php

// A CLI process for OutputCaptureTest: prints and logs in every way a worker might, then writes
// what Zharp sent to the JSON file named by its first argument. The second argument:
//   "all"     — logs => true (everything is sent);
//   "off"     — logs => true, capture_output => false;
//   "default" — no options: logs off, lines stamped.
// ZHARP_* environment variables apply on top.

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use Zharp\Internal\OutputCapture;
use Zharp\Monolog\LogStamping;
use Zharp\Monolog\ZharpHandler;
use Zharp\Zharp;

$file = $argv[1];
$mode = $argv[2] ?? 'all';
$logs = new ArrayObject();
$traceId = null;
$requestIds = null;
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
    'logs' => $mode === 'default' ? null : true,
    'capture_output' => $mode === 'off' ? false : null,
], static fn ($v) => $v !== null));

// Registered after Zharp's own, so it runs once Zharp has sent everything.
register_shutdown_function(static function () use ($file, $logs, &$traceId, &$requestIds, &$previousCalls): void {
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
        'request_ids' => $requestIds,
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

// Stamping: lines written during a request end with its ids; a JSON record gets fields.
$text = new Logger('web');
$text->pushHandler(new StreamHandler('php://output'));
LogStamping::apply($text);
$jsonHandler = new StreamHandler('php://output');
$jsonHandler->setFormatter(new JsonFormatter());
$json = new Logger('web', [$jsonHandler]);
LogStamping::apply($json);
$withZharp = new Logger('app', [new ZharpHandler('debug')]);

Zharp::startSpan('request', static function () use (&$requestIds, $text, $json, $withZharp, $stdout, $stderr): void {
    $context = Span::getCurrent()->getContext();
    $requestIds = [$context->getTraceId(), $context->getSpanId()];
    echo "stamp-echo inside\n";
    $text->info('stamp-monolog line', ['order' => 7]);
    $json->warning('stamp-json record', ['order' => 7]);
    fwrite($stdout, "stamp-console inside\n");
    fwrite($stderr, "stamp-console-err inside\n");
    echo "stamp-already trace_id=abc\n";
    // Sent whether logs are on or not.
    Zharp::info('explicit zharp log');
    Zharp::captureError(new RuntimeException('caught one'));
    $withZharp->error('logged with an exception', ['exception' => new RuntimeException('logged boom')]);
    // Sent only with logs on.
    $withZharp->info('handler info');
});
$text->info('stamp-outside monolog');
echo "stamp-outside echo\n";

echo "[Zharp] the SDK talking about itself\n";
echo "no newline at the end";
