<?php

declare(strict_types=1);

namespace Zharp\Tests;

use ArrayObject;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zharp\Internal\LogLine;
use Zharp\Internal\OutputCapture;
use Zharp\Symfony\ConsoleSubscriber;
use Zharp\Zharp;

/** capture_output: PHP warnings, and what a CLI process prints, arrive as logs, once. */
final class OutputCaptureTest extends TestCase
{
    private ArrayObject $logs;

    protected function setUp(): void
    {
        Zharp::resetForTests();
        $this->logs = new ArrayObject();
    }

    protected function tearDown(): void
    {
        Zharp::resetForTests();
        unset($_SERVER['ZHARP_CAPTURE_OUTPUT']);
    }

    /** @param array<string, mixed> $options */
    private function start(array $options = []): void
    {
        self::assertTrue(Zharp::init($options + [
            'key' => 'zpk_test',
            'service' => 'php-unit',
            'capture_uncaught' => false,
            'span_exporter' => new InMemorySpans(new ArrayObject()),
            'log_exporter' => new InMemoryLogs($this->logs),
        ]));
    }

    /** @return list<ReadableLogRecord> */
    private function logs(): array
    {
        return array_values($this->logs->getArrayCopy());
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return array_map(static fn (ReadableLogRecord $r): string => (string) $r->getBody(), $this->logs());
    }

    /** @return resource */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    // ── A real CLI process ───────────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $env
     * @return array{stdout: string, stderr: string, code: int, result: array<string, mixed>}
     */
    private static function child(string $mode = 'all', array $env = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'zharp-output-');
        self::assertIsString($file);
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=1', __DIR__ . '/fixtures/output-child.php', $file, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['OTEL_PHP_DETECTORS' => 'none', 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $result = json_decode((string) file_get_contents($file), true);
        @unlink($file);
        self::assertIsArray($result, "child wrote nothing; stderr: {$stderr}");

        return ['stdout' => $stdout, 'stderr' => $stderr, 'code' => $code, 'result' => $result];
    }

    /**
     * @param array<string, mixed> $result
     * @return list<array<string, mixed>>
     */
    private static function matching(array $result, string $text): array
    {
        return array_values(array_filter($result['records'], static fn (array $r): bool => str_contains((string) $r['body'], $text)));
    }

    public function testEverythingACliProcessPrintsArrivesOnceWithItsLevel(): void
    {
        $run = self::child();
        self::assertSame(0, $run['code'], $run['stderr']);

        // Printed exactly as before.
        foreach ([
            "plain stdout line\n", "[warn] disk almost full\n", "split across two writes\n", "inside the span\n",
            "\033[31mERROR\033[0m coloured\n", "app.WARNING: monolog once", "app.ERROR: monolog printed first",
            'Warning: careful now in ', "console says hi\n", "[Zharp] the SDK talking about itself\n",
        ] as $text) {
            self::assertStringContainsString($text, $run['stdout']);
        }
        self::assertStringEndsWith('no newline at the end', $run['stdout']);
        self::assertStringContainsString("console stderr line\nERROR console failure\n", $run['stderr']);

        $expected = [
            // body => [severity, source]
            'plain stdout line' => [9, 'stdout'],
            '[warn] disk almost full' => [13, 'stdout'],
            'level=error msg="payment failed"' => [17, 'stdout'],
            'json says error' => [17, 'stdout'],
            'monolog formatted line' => [21, 'stdout'],
            'ERROR coloured' => [17, 'stdout'],
            'split across two writes' => [9, 'stdout'],
            'inside the span' => [9, 'stdout'],
            'console says hi' => [9, 'stdout'],
            'console stderr line' => [13, 'stderr'],
            'ERROR console failure' => [17, 'stderr'],
            'no newline at the end' => [9, 'stdout'],
        ];
        $result = $run['result'];
        foreach ($expected as $body => [$severity, $source]) {
            $found = array_values(array_filter($result['records'], static fn (array $r): bool => $r['body'] === $body));
            self::assertCount(1, $found, $body);
            self::assertSame($severity, $found[0]['severity'], $body);
            self::assertSame($source, $found[0]['attributes']['log.source'], $body);
        }
        self::assertSame('production', self::matching($result, 'monolog formatted line')[0]['attributes']['log.channel']);

        // The line keeps the trace it was printed in.
        self::assertNotNull($result['trace_id']);
        self::assertSame($result['trace_id'], self::matching($result, 'inside the span')[0]['trace_id']);
        self::assertNull(self::matching($result, 'plain stdout line')[0]['trace_id']);

        // Sent by Monolog's handler, printed by Monolog too: once, either handler order.
        foreach (['monolog once', 'monolog printed first'] as $text) {
            $found = self::matching($result, $text);
            self::assertCount(1, $found, $text);
            self::assertArrayNotHasKey('log.source', $found[0]['attributes']);
        }

        // A PHP warning shown on stdout: once, from the error handler; the app's handler still ran.
        $warning = self::matching($result, 'careful now');
        self::assertCount(1, $warning);
        self::assertSame('Warning: careful now', $warning[0]['body']);
        self::assertSame(13, $warning[0]['severity']);
        self::assertSame('E_USER_WARNING', $warning[0]['attributes']['php.error.type']);
        self::assertSame([], self::matching($result, 'silenced'));
        self::assertSame(2, $result['previous_calls']);

        // The SDK's own lines are not the app's.
        self::assertSame([], self::matching($result, '[Zharp]'));
        self::assertCount(count($expected) + 3, $result['records']);
    }

    public function testOffPrintsTheSameAndSendsNoPrintedLines(): void
    {
        foreach ([self::child('off'), self::child('all', ['ZHARP_CAPTURE_OUTPUT' => 'false'])] as $run) {
            self::assertSame(0, $run['code'], $run['stderr']);
            self::assertStringContainsString("plain stdout line\n", $run['stdout']);
            self::assertStringContainsString("console stderr line\n", $run['stderr']);
            $printed = array_filter($run['result']['records'], static fn (array $r): bool => isset($r['attributes']['log.source']));
            self::assertSame([], array_values($printed));
            // No warning capture either; Monolog's handler still sends.
            self::assertSame([], self::matching($run['result'], 'careful now'));
            self::assertCount(1, self::matching($run['result'], 'monolog once'));
            self::assertSame(2, $run['result']['previous_calls']);
        }
    }

    // ── In process ───────────────────────────────────────────────────────────────────────────

    public function testPhpOutputIsNotBufferedUnderPhpunit(): void
    {
        $this->start();
        self::assertFalse(OutputCapture::isBuffering());
        self::assertFalse(OutputCapture::outputLayerSafe());
    }

    public function testAConsoleStreamLineArrivesInItsTraceAndStillWrites(): void
    {
        $this->start();
        $stream = self::memory();
        self::assertTrue(OutputCapture::tapStream($stream, 'stderr'));
        $traceId = Zharp::startSpan('handle', static function () use ($stream): string {
            fwrite($stream, "in the request\n");

            return Span::getCurrent()->getContext()->getTraceId();
        });
        fwrite($stream, 'level=info half a ');
        fwrite($stream, "line\n");
        Zharp::flush();

        rewind($stream);
        self::assertSame("in the request\nlevel=info half a line\n", stream_get_contents($stream));
        $logs = $this->logs();
        self::assertSame(['in the request', 'level=info half a line'], $this->bodies());
        self::assertSame($traceId, $logs[0]->getSpanContext()->getTraceId());
        self::assertSame(13, $logs[0]->getSeverityNumber());
        self::assertSame('stderr', $logs[0]->getAttributes()->get('log.source'));
        self::assertSame(9, $logs[1]->getSeverityNumber());
    }

    public function testALineAlreadySentIsNotSentAgain(): void
    {
        $this->start();
        $stream = self::memory();
        OutputCapture::tapStream($stream, 'stdout');
        Zharp::warn('card declined for order 7');
        fwrite($stream, "2026-11-17 09:00:00 WARN card declined for order 7\n");
        fwrite($stream, "something else\n");
        Zharp::flush();
        self::assertSame(['card declined for order 7', 'something else'], $this->bodies());
    }

    public function testOnlyStdoutAndStderrStreamsAreTapped(): void
    {
        $this->start();
        // Memory, files and php://output (the output buffer's) are not the process's output.
        self::assertFalse(OutputCapture::tapStream(self::memory()));
        $output = fopen('php://output', 'w');
        self::assertFalse(OutputCapture::tapStream($output));
        OutputCapture::tapConsole(new \stdClass());
        (new ConsoleSubscriber())->onCommand(new \stdClass());
        self::assertSame([], $this->logs());
    }

    public function testOffTapsNothing(): void
    {
        $_SERVER['ZHARP_CAPTURE_OUTPUT'] = 'false';
        $this->start();
        self::assertFalse(Zharp::capturesOutput());
        self::assertFalse(OutputCapture::tapStream(self::memory(), 'stdout'));
    }

    public function testPhpWarningsChainToThePreviousHandler(): void
    {
        $reporting = error_reporting(E_ALL);
        $seen = [];
        set_error_handler(static function (int $type, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });
        try {
            $this->start(['capture_uncaught' => true]);
            trigger_error('deprecated thing', E_USER_DEPRECATED);
            trigger_error('a notice', E_USER_NOTICE);
            @trigger_error('quiet please', E_USER_WARNING);
            Zharp::startSpan('request', static function (): void {
                trigger_error('in the request', E_USER_WARNING);
            });
            Zharp::resetForTests();
            // Removed again: back to the app's handler.
            trigger_error('after reset', E_USER_NOTICE);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
        self::assertSame(['deprecated thing', 'a notice', 'quiet please', 'in the request', 'after reset'], $seen);
        self::assertSame(['Deprecated: deprecated thing', 'Notice: a notice', 'Warning: in the request'], $this->bodies());
        $logs = $this->logs();
        self::assertSame([13, 10, 13], array_map(static fn (ReadableLogRecord $r) => $r->getSeverityNumber(), $logs));
        self::assertSame('E_USER_DEPRECATED', $logs[0]->getAttributes()->get('php.error.type'));
        self::assertTrue($logs[2]->getSpanContext()->isValid());
    }

    public function testFrameworksKeepTheirOwnErrorHandling(): void
    {
        $reporting = error_reporting(E_ALL);
        $seen = 0;
        set_error_handler(static function () use (&$seen): bool {
            $seen++;

            return true;
        });
        try {
            $this->start(); // capture_uncaught => false, as Laravel and Symfony start it
            trigger_error('framework handles this', E_USER_WARNING);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
        self::assertSame(1, $seen);
        self::assertSame([], $this->logs());
    }

    /**
     * @return iterable<string, array{0: string, 1: int|null, 2: string}>
     */
    public static function lines(): iterable
    {
        yield 'plain' => ['Processing jobs from the default queue', null, 'Processing jobs from the default queue'];
        yield 'bracketed word' => ['[warn] disk almost full', 13, '[warn] disk almost full'];
        yield 'key value' => ['ts=1 level=error msg=boom', 17, 'ts=1 level=error msg=boom'];
        yield 'laravel component' => ['   ERROR  Command failed.', 17, '   ERROR  Command failed.'];
        yield 'json level' => ['{"level":"warning","message":"slow query"}', 13, 'slow query'];
        yield 'json monolog' => ['{"message":"boom","context":{},"level":400,"level_name":"ERROR","channel":"app"}', 17, 'boom'];
        yield 'json pino' => ['{"level":50,"msg":"pino boom"}', 17, 'pino boom'];
        yield 'monolog line' => ['[2026-11-17 08:33:00] local.ERROR: Oops {"id":7} []', 17, 'Oops {"id":7}'];
        yield 'php deprecated' => ['PHP Deprecated:  strlen(): Passing null', 13, 'PHP Deprecated:  strlen(): Passing null'];
        yield 'php fatal' => ['PHP Fatal error:  Uncaught Exception: x', 21, 'PHP Fatal error:  Uncaught Exception: x'];
        yield 'not json' => ['{not json}', null, '{not json}'];
        yield 'word inside a word' => ['information about errors', null, 'information about errors'];
    }

    #[DataProvider('lines')]
    public function testTheLevelIsReadFromTheLine(string $line, ?int $severity, string $body): void
    {
        $parsed = LogLine::parse($line);
        self::assertSame($severity, $parsed['level'][0] ?? null);
        self::assertSame($body, $parsed['body']);
    }

    public function testLinesAreCleanedAndCapped(): void
    {
        self::assertSame('ERROR red', LogLine::clean("\033[31mERROR\033[0m red \r"));
        self::assertSame(LogLine::MAX_LINE, strlen(LogLine::clean(str_repeat('x', LogLine::MAX_LINE + 10))));
    }
}
