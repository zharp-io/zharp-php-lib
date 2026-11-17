<?php

declare(strict_types=1);

namespace Zharp\Internal;

use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;
use Zharp\Zharp;

/**
 * What a CLI process prints, as logs: the net under the exact captures (Zharp::log, Monolog,
 * Laravel's log events, PHP warnings), for whatever reaches the output some other way. Each line
 * keeps the trace and span it was printed in, and still prints exactly as before.
 *
 * Where it listens, and why only there:
 *
 * - PHP's output (echo, print, printf, var_dump, warnings shown with display_errors): an output
 *   buffer that hands every write straight on (chunk size 1). Only in the `cli` SAPI — queue
 *   workers, artisan and console commands, scripts, Swoole/RoadRunner/Octane workers. Under
 *   PHP-FPM, Apache, FrankenPHP or `php -S`, PHP's output IS the HTTP response, and a response
 *   body is never a log. Octane wraps each request in a buffer of its own, so a request's output
 *   goes to the response and never reaches this one. Not under PHPUnit either: it counts output
 *   buffers around every test.
 * - A console command's own output stream (Symfony Console, so artisan too): a write filter on
 *   the stream the command prints to, added when the command starts — after the console has
 *   decided on colours. PHP can't hand a filtered stream to proc_open or tell whether it is a
 *   terminal, so the process's STDOUT and STDERR constants are left alone: a child process given
 *   STDOUT would fail to start.
 *
 * Nothing is sent from inside the output buffer (PHP forbids output there, and a warning shown
 * while sending would be fatal). Lines wait in a queue and go when Zharp next sends anything, at
 * flush() (after every Laravel job, response and command) and when the script ends; from a
 * console stream, lines more than a second old go on its next write. Waiting a moment also lets a
 * line that an exact capture sends just after printing it be recognised and not sent twice.
 *
 * @internal
 */
final class OutputCapture
{
    private const HANDLER = self::class . '::handle';
    private const FILTER = 'zharp.output';
    private const QUEUE_MAX = 1000;
    private const DRAIN_AFTER_SECONDS = 1.0;
    private const DRAIN_COUNT = 50;

    private static bool $active = false;
    private static bool $buffering = false;
    private static bool $inHandler = false;
    private static bool $draining = false;
    private static bool $filterRegistered = false;
    /** @var array<string, array{text: string, context: ContextInterface, time: int}> */
    private static array $pending = [];
    /** @var list<array{source: string, line: string, context: ContextInterface, attributes: array<string, bool|int|float|string>, time: int, at: float}> */
    private static array $queue = [];
    /** @var array<int, true> */
    private static array $tapped = [];
    /** @var array<string, string> line buffer key → source */
    private static array $sources = [];

    /** Start capturing; $outputLayer also buffers PHP's output where that is safe. */
    public static function start(bool $outputLayer = true): void
    {
        self::$active = true;
        if ($outputLayer && !self::$buffering && self::outputLayerSafe()) {
            try {
                self::$buffering = ob_start(self::HANDLER, 1);
            } catch (Throwable) {
                self::$buffering = false;
            }
        }
    }

    /** Whether PHP's output can be read as logs: the CLI, outside PHPUnit. */
    public static function outputLayerSafe(): bool
    {
        return PHP_SAPI === 'cli' && !defined('PHPUNIT_COMPOSER_INSTALL') && !defined('__PHPUNIT_PHAR__');
    }

    public static function isBuffering(): bool
    {
        return self::$buffering;
    }

    /**
     * The output buffer. Only copies: it returns what it was given and sends nothing.
     *
     * @param mixed $buffer
     * @param mixed $phase
     */
    public static function handle($buffer, $phase = 0): string
    {
        $buffer = (string) $buffer;
        if ($buffer !== '' && self::$active && !self::$inHandler && !((int) $phase & PHP_OUTPUT_HANDLER_CLEAN) && !SentLines::isQuiet()) {
            self::$inHandler = true;
            try {
                self::take('output', 'stdout', $buffer);
            } catch (Throwable) {
            } finally {
                self::$inHandler = false;
            }
        }

        return $buffer;
    }

    /**
     * Tap a console command's output (Symfony Console's StreamOutput / ConsoleOutput, which
     * artisan uses too): its stream, and its error output's.
     */
    public static function tapConsole(mixed $output): void
    {
        if (!self::$active || PHP_SAPI !== 'cli' || !is_object($output)) {
            return;
        }
        try {
            if (method_exists($output, 'getStream')) {
                self::tapStream($output->getStream());
            }
            if (method_exists($output, 'getErrorOutput')) {
                $error = $output->getErrorOutput();
                if (is_object($error) && $error !== $output && method_exists($error, 'getStream')) {
                    self::tapStream($error->getStream());
                }
            }
        } catch (Throwable) {
        }
    }

    /**
     * A write filter on a stream that goes to the process's stdout or stderr (opened as
     * php://stdout or php://stderr). Anything else — php://output (the output buffer sees it),
     * memory, files — is left alone unless $source says what it is.
     *
     * @param mixed $stream
     */
    public static function tapStream($stream, ?string $source = null): bool
    {
        if (!self::$active || !is_resource($stream) || get_resource_type($stream) !== 'stream') {
            return false;
        }
        $id = (int) $stream;
        if (isset(self::$tapped[$id])) {
            return true;
        }
        if ($source === null) {
            $uri = strtolower((string) (stream_get_meta_data($stream)['uri'] ?? ''));
            $source = ['php://stdout' => 'stdout', 'php://stderr' => 'stderr'][$uri] ?? null;
            if ($source === null) {
                return false;
            }
        }
        if (!self::$filterRegistered) {
            self::$filterRegistered = in_array(self::FILTER, stream_get_filters(), true)
                || stream_filter_register(self::FILTER, OutputFilter::class);
        }
        if (!self::$filterRegistered) {
            return false;
        }
        if (@stream_filter_append($stream, self::FILTER, STREAM_FILTER_WRITE, ['key' => 'stream' . $id, 'source' => $source]) === false) {
            return false;
        }
        self::$tapped[$id] = true;

        return true;
    }

    /** What a filtered stream wrote. */
    public static function fromStream(string $key, string $source, string $data): void
    {
        if (!self::$active || $data === '' || SentLines::isQuiet()) {
            return;
        }
        self::take($key, $source, $data);
        if (count(self::$queue) >= self::DRAIN_COUNT) {
            self::drain();
        } else {
            self::drain(self::DRAIN_AFTER_SECONDS);
        }
    }

    /**
     * Send the queued lines (those older than $olderThan seconds, if given). A line an exact
     * capture already sent is dropped.
     */
    public static function drain(?float $olderThan = null): void
    {
        if (self::$draining || self::$inHandler || self::$queue === []) {
            return;
        }
        self::$draining = true;
        try {
            $now = microtime(true);
            $later = [];
            foreach (self::$queue as $item) {
                if ($olderThan !== null && $now - $item['at'] < $olderThan) {
                    $later[] = $item;
                    continue;
                }
                if (SentLines::alreadySent($item['line'])) {
                    continue;
                }
                $parsed = LogLine::parse($item['line']);
                if (trim($parsed['body']) === '') {
                    continue;
                }
                [$severity, $text] = $parsed['level'] ?? ($item['source'] === 'stderr' ? [13, 'WARN'] : [9, 'INFO']);
                Zharp::emitLine(
                    $severity,
                    $text,
                    $parsed['body'],
                    ['log.source' => $item['source'], 'log.iostream' => $item['source']] + $parsed['attributes'] + $item['attributes'],
                    $item['context'],
                    $item['time'],
                );
            }
            self::$queue = $later;
        } catch (Throwable) {
            self::$queue = [];
        } finally {
            self::$draining = false;
        }
    }

    /** Lines still being written are complete now; send everything. */
    public static function finish(): void
    {
        foreach (array_keys(self::$pending) as $key) {
            $pending = self::$pending[$key];
            unset(self::$pending[$key]);
            self::complete($key === 'output' ? 'stdout' : self::sourceOf($key), $pending['text'], $pending['context'], $pending['time']);
        }
        self::drain();
    }

    /** Stop: the rest is sent, the output buffer is removed if it is on top (else it just passes output on). */
    public static function stop(): void
    {
        try {
            self::finish();
        } catch (Throwable) {
        }
        self::$active = false;
        if (self::$buffering) {
            $handlers = ob_list_handlers();
            if ($handlers !== [] && end($handlers) === self::HANDLER) {
                @ob_end_flush();
                self::$buffering = false;
            }
        }
    }

    /** @internal For tests. */
    public static function reset(): void
    {
        self::stop();
        self::$pending = [];
        self::$queue = [];
        self::$sources = [];
    }

    private static function sourceOf(string $key): string
    {
        return self::$sources[$key] ?? 'stdout';
    }

    /** Split into lines; a line belongs to the trace it started in. */
    private static function take(string $key, string $source, string $text): void
    {
        self::$sources[$key] = $source;
        $pending = self::$pending[$key] ?? null;
        $context = $pending['context'] ?? Context::getCurrent();
        $time = $pending['time'] ?? self::now();
        $text = ($pending['text'] ?? '') . $text;
        if (!str_contains($text, "\n")) {
            if (strlen($text) > LogLine::MAX_LINE) {
                unset(self::$pending[$key]);
                self::complete($source, $text, $context, $time);
            } else {
                self::$pending[$key] = ['text' => $text, 'context' => $context, 'time' => $time];
            }

            return;
        }
        $lines = explode("\n", $text);
        $rest = array_pop($lines);
        foreach ($lines as $line) {
            self::complete($source, $line, $context, $time);
        }
        if ($rest === '') {
            unset(self::$pending[$key]);
        } else {
            self::$pending[$key] = ['text' => $rest, 'context' => Context::getCurrent(), 'time' => self::now()];
        }
    }

    private static function complete(string $source, string $raw, ContextInterface $context, int $time): void
    {
        $line = LogLine::clean($raw);
        if (trim($line) === '' || str_contains($line, '[Zharp]')) {
            return;
        }
        self::$queue[] = [
            'source' => $source,
            'line' => $line,
            'context' => $context,
            // The user and session as they are now, not when the queue is sent.
            'attributes' => Zharp::contextAttributes($context),
            'time' => $time,
            'at' => microtime(true),
        ];
        if (count(self::$queue) > self::QUEUE_MAX) {
            array_shift(self::$queue);
        }
    }

    private static function now(): int
    {
        return (int) (microtime(true) * 1e9);
    }
}
