<?php

declare(strict_types=1);

namespace Zharp\Tests;

use ArrayObject;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zharp\Internal\LogLine;
use Zharp\Internal\OutputCapture;
use Zharp\Monolog\LogStamping;
use Zharp\Monolog\StampingFormatter;
use Zharp\Monolog\ZharpHandler;
use Zharp\Zharp;

/**
 * Logs off by default (the Zharp agent brings the app's lines in), and every line written during a
 * request stamped with its ids so the agent's copy is linked to it.
 */
final class LogCorrelationTest extends TestCase
{
    /** What Zharp's backend reads at the end of a line. */
    private const BACKEND = '/\s*\btrace_id=([0-9a-f]{32})\s+span_id=([0-9a-f]{16})\s*$/i';

    private ArrayObject $logs;

    protected function setUp(): void
    {
        Zharp::resetForTests();
        $this->logs = new ArrayObject();
    }

    protected function tearDown(): void
    {
        Zharp::resetForTests();
        unset($_SERVER['ZHARP_LOGS'], $_SERVER['ZHARP_LOG_CORRELATION']);
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

    /** @return list<string> */
    private function bodies(): array
    {
        return array_map(static fn (ReadableLogRecord $r): string => (string) $r->getBody(), array_values($this->logs->getArrayCopy()));
    }

    /** @return array{0: Logger, 1: resource} */
    private static function logger(?\Monolog\Formatter\FormatterInterface $formatter = null): array
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $handler = new StreamHandler($stream);
        if ($formatter !== null) {
            $handler->setFormatter($formatter);
        }
        $logger = new Logger('web', [$handler]);
        LogStamping::apply($logger);

        return [$logger, $stream];
    }

    /** @param resource $stream @return list<string> */
    private static function lines($stream): array
    {
        rewind($stream);

        return array_values(array_filter(explode("\n", (string) stream_get_contents($stream)), static fn (string $l): bool => $l !== ''));
    }

    /** @return array{0: string, 1: string} trace and span id of the span the work ran in */
    private static function inRequest(callable $work): array
    {
        return Zharp::startSpan('request', static function () use ($work): array {
            $work();
            $context = Span::getCurrent()->getContext();

            return [$context->getTraceId(), $context->getSpanId()];
        });
    }

    // ── Logs off by default ─────────────────────────────────────────────────────────────────

    public function testByDefaultNothingLoggedIsSentButErrorsAndZharpLogAre(): void
    {
        $reporting = error_reporting(E_ALL);
        $previous = 0;
        set_error_handler(static function () use (&$previous): bool {
            $previous++;

            return true;
        });
        try {
            $this->start(['capture_uncaught' => true]);
            self::assertFalse(Zharp::sendsLogs());
            $logger = new Logger('app', [new ZharpHandler('debug')]);
            $logger->warning('a log line');
            trigger_error('a php warning', E_USER_WARNING);
            Zharp::info('explicit');
            Zharp::captureError(new RuntimeException('caught'));
            $logger->error('logged with an exception', ['exception' => new RuntimeException('boom')]);
            // Already reported: the log line doesn't make it an error twice.
            $reported = new RuntimeException('reported');
            Zharp::captureError($reported);
            $logger->error('reported again', ['exception' => $reported]);
        } finally {
            Zharp::resetForTests();
            restore_error_handler();
            error_reporting($reporting);
        }
        self::assertSame(1, $previous);
        self::assertSame(['explicit', 'RuntimeException: caught', 'logged with an exception', 'RuntimeException: reported'], $this->bodies());
        $records = array_values($this->logs->getArrayCopy());
        self::assertSame('boom', $records[2]->getAttributes()->get('exception.message'));
    }

    public function testTheSwitch(): void
    {
        $this->start(['logs' => true]);
        self::assertTrue(Zharp::sendsLogs());
        Zharp::resetForTests();

        foreach (['true', '1', 'yes', 'on'] as $value) {
            $_SERVER['ZHARP_LOGS'] = $value;
            $this->start();
            self::assertTrue(Zharp::sendsLogs(), $value);
            (new Logger('app', [new ZharpHandler('debug')]))->info("sent with {$value}");
            Zharp::resetForTests();
        }
        $_SERVER['ZHARP_LOGS'] = 'false';
        $this->start();
        self::assertFalse(Zharp::sendsLogs());
        self::assertSame(['sent with true', 'sent with 1', 'sent with yes', 'sent with on'], $this->bodies());
    }

    // ── The stamp ────────────────────────────────────────────────────────────────────────────

    public function testAMonologLineWrittenInARequestEndsWithItsIds(): void
    {
        $this->start();
        [$logger, $stream] = self::logger();
        $ids = self::inRequest(static function () use ($logger): void {
            $logger->info('order placed', ['order' => 7]);
        });
        $logger->info('outside a request');

        $lines = self::lines($stream);
        self::assertCount(2, $lines);
        self::assertMatchesRegularExpression(self::BACKEND, $lines[0]);
        preg_match(self::BACKEND, $lines[0], $m);
        self::assertSame($ids, [$m[1], $m[2]]);
        self::assertStringEndsWith('web.INFO: order placed {"order":7} [] trace_id=' . $ids[0] . ' span_id=' . $ids[1], $lines[0]);
        // Lines outside a request are unchanged.
        self::assertStringEndsWith('web.INFO: outside a request [] []', $lines[1]);
        self::assertStringNotContainsString('trace_id', $lines[1]);
        // Logs off: stamped, not sent.
        self::assertSame([], $this->bodies());
    }

    public function testAJsonRecordGetsFields(): void
    {
        $this->start();
        [$logger, $stream] = self::logger(new JsonFormatter());
        $ids = self::inRequest(static function () use ($logger): void {
            $logger->warning('slow query', ['ms' => 900]);
        });
        $logger->warning('outside');
        $lines = self::lines($stream);
        $record = json_decode($lines[0], true);
        self::assertIsArray($record);
        self::assertSame('slow query', $record['message']);
        self::assertSame($ids[0], $record['trace_id']);
        self::assertSame($ids[1], $record['span_id']);
        self::assertArrayNotHasKey('trace_id', (array) json_decode($lines[1], true));
    }

    public function testCorrelationOffLeavesLinesAlone(): void
    {
        $_SERVER['ZHARP_LOG_CORRELATION'] = 'false';
        $this->start();
        self::assertFalse(Zharp::stampsLogs());
        [$logger, $stream] = self::logger();
        self::inRequest(static fn () => $logger->info('in a request'));
        self::assertStringNotContainsString('trace_id', self::lines($stream)[0]);
    }

    public function testHandlersInsideGroupsAndWrappersAreStampedOnce(): void
    {
        $this->start();
        $streams = [fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
        $logger = new Logger('web', [
            new GroupHandler([new StreamHandler($streams[0])]),
            new FingersCrossedHandler(new StreamHandler($streams[1])),
        ]);
        LogStamping::apply($logger);
        LogStamping::apply($logger);
        self::inRequest(static fn () => $logger->error('failed'));
        foreach ($streams as $stream) {
            $line = self::lines($stream)[0];
            self::assertMatchesRegularExpression(self::BACKEND, $line);
            self::assertSame(1, substr_count($line, 'trace_id='));
        }
        $handler = $logger->getHandlers()[1];
        self::assertInstanceOf(StampingFormatter::class, $handler->getFormatter());
    }

    public function testAConsoleStreamIsStampedInARequestOnly(): void
    {
        $this->start();
        $stream = fopen('php://memory', 'w+');
        self::assertTrue(OutputCapture::tapStream($stream, 'stdout'));
        $ids = self::inRequest(static function () use ($stream): void {
            fwrite($stream, "first\nsecond\n");
            fwrite($stream, "already trace_id=x\n");
            fwrite($stream, '{"msg":"json"}' . "\n");
        });
        fwrite($stream, "outside\n");
        rewind($stream);
        $stamp = " trace_id={$ids[0]} span_id={$ids[1]}";
        self::assertSame(
            "first{$stamp}\nsecond{$stamp}\nalready trace_id=x\n" . '{"msg":"json","trace_id":"' . $ids[0] . '","span_id":"' . $ids[1] . '"}' . "\noutside\n",
            stream_get_contents($stream),
        );
        self::assertSame([], $this->bodies());
    }

    public function testStampLine(): void
    {
        $t = str_repeat('a', 32);
        $s = str_repeat('b', 16);
        self::assertSame("hello trace_id={$t} span_id={$s}", LogLine::stamp('hello', $t, $s));
        self::assertSame("hello trace_id={$t} span_id={$s}  \r", LogLine::stamp("hello  \r", $t, $s));
        self::assertSame("{\"trace_id\":\"{$t}\",\"span_id\":\"{$s}\"}", LogLine::stamp('{}', $t, $s));
        self::assertSame('"end": 1}', LogLine::stamp('"end": 1}', $t, $s));
        self::assertSame('   ', LogLine::stamp('   ', $t, $s));
        self::assertSame('[Zharp] sdk', LogLine::stamp('[Zharp] sdk', $t, $s));
        self::assertSame("a trace_id={$t} span_id={$s}\nb", LogLine::stampText("a\nb", $t, $s));
        self::assertSame("a trace_id={$t} span_id={$s}\nb trace_id={$t} span_id={$s}", LogLine::stampText("a\nb", $t, $s, true));
        // A captured line's body drops the stamp.
        self::assertSame('placed', LogLine::parse("[2026-11-17 08:33:00] local.INFO: placed trace_id={$t} span_id={$s}")['body']);
    }
}
