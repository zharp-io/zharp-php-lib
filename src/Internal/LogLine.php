<?php

declare(strict_types=1);

namespace Zharp\Internal;

/**
 * A printed line, read for its level and, for the formats worth knowing, its own message: a JSON
 * record's level (Monolog's JsonFormatter, pino-style numbers), Monolog's line format
 * (`[2026-11-17 08:33:00] production.ERROR: message`), PHP's own error display (`PHP Warning:`,
 * `Deprecated:`), or a level word near the start (`ERROR`, `[warn]`, `level=error`). Nothing
 * found leaves the level to the stream: stdout is info, stderr a warning.
 *
 * @internal
 */
final class LogLine
{
    public const MAX_LINE = 32 * 1024;

    /** Level word → [OTLP severity number, severity text]. */
    private const LEVELS = [
        'trace' => [1, 'TRACE'],
        'verbose' => [5, 'DEBUG'],
        'debug' => [5, 'DEBUG'],
        'log' => [9, 'INFO'],
        'info' => [9, 'INFO'],
        'notice' => [10, 'NOTICE'],
        'warn' => [13, 'WARN'],
        'warning' => [13, 'WARN'],
        'deprecated' => [13, 'WARN'],
        'err' => [17, 'ERROR'],
        'error' => [17, 'ERROR'],
        'crit' => [21, 'CRITICAL'],
        'critical' => [21, 'CRITICAL'],
        'fatal' => [21, 'FATAL'],
        'alert' => [22, 'ALERT'],
        'emerg' => [23, 'EMERGENCY'],
        'emergency' => [23, 'EMERGENCY'],
    ];

    /** Monolog's numeric levels (100–600) and pino's (10–60), highest first. */
    private const MONOLOG = [600 => 'emergency', 550 => 'alert', 500 => 'critical', 400 => 'error', 300 => 'warning', 250 => 'notice', 200 => 'info', 0 => 'debug'];
    private const PINO = [60 => 'fatal', 50 => 'error', 40 => 'warn', 30 => 'info', 20 => 'debug', 0 => 'trace'];

    /** What Zharp's backend reads at the end of a line: keep in step with stamp(). */
    public const STAMP = '/\s*\btrace_id=([0-9a-f]{32})\s+span_id=([0-9a-f]{16})\s*$/i';

    private const ANSI ='/\x1b\[[0-9;?]*[ -\/]*[@-~]/';
    /** `[2026-11-17 08:33:00] production.ERROR: message {"context":1} []` */
    private const MONOLOG_LINE = '/^\[[^\]]+\]\s+([\w.\-]+)\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY):\s?(.*)$/s';
    /** `PHP Warning:  …`, `Deprecated: …`, `Fatal error: …` */
    private const PHP_ERROR = '/^(?:PHP )?(Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated):\s/';
    private const PHP_ERROR_LEVELS = ['fatal error' => 'fatal', 'parse error' => 'fatal', 'recoverable fatal error' => 'error', 'warning' => 'warn', 'notice' => 'notice', 'deprecated' => 'deprecated'];
    /** The first level word in the opening of a line: `ERROR`, `[warn]`, `level=error`. */
    private const WORD = '/(?:^|[\s\[(|:=])(trace|verbose|debug|info|notice|warn(?:ing)?|err(?:or)?|crit(?:ical)?|fatal|emerg|alert)(?=$|[\s\]):|,])/i';

    /** Colour codes and trailing whitespace off, capped. */
    public static function clean(string $raw): string
    {
        if (strlen($raw) > self::MAX_LINE) {
            $raw = substr($raw, 0, self::MAX_LINE);
        }
        $line = str_contains($raw, "\x1b") ? (string) preg_replace(self::ANSI, '', $raw) : $raw;

        return rtrim($line);
    }

    /**
     * @return array{level: array{0: int, 1: string}|null, body: string, attributes: array<string, string>}
     */
    public static function parse(string $line): array
    {
        // A stamp (a Monolog line written in a request) is not part of the message; the record
        // carries the trace itself.
        if (str_contains($line, 'trace_id=')) {
            $line = (string) preg_replace(self::STAMP, '', $line);
        }
        if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
            $record = json_decode($line, true);
            if (is_array($record)) {
                $message = $record['message'] ?? $record['msg'] ?? $record['event'] ?? null;
                $attributes = [];
                if (isset($record['channel']) && is_string($record['channel'])) {
                    $attributes['log.channel'] = $record['channel'];
                }

                return [
                    'level' => self::levelOf($record['level_name'] ?? $record['level'] ?? $record['severity'] ?? $record['lvl'] ?? null),
                    'body' => is_string($message) && $message !== '' ? $message : $line,
                    'attributes' => $attributes,
                ];
            }
        }
        if (preg_match(self::MONOLOG_LINE, $line, $m)) {
            return [
                'level' => self::LEVELS[strtolower($m[2])],
                'body' => (string) preg_replace('/(?:\s+\[\])+$/', '', $m[3]),
                'attributes' => ['log.channel' => $m[1]],
            ];
        }
        if (preg_match(self::PHP_ERROR, $line, $m)) {
            return ['level' => self::LEVELS[self::PHP_ERROR_LEVELS[strtolower($m[1])]], 'body' => $line, 'attributes' => []];
        }
        $level = preg_match(self::WORD, substr($line, 0, 120), $m) ? (self::LEVELS[strtolower($m[1])] ?? null) : null;

        return ['level' => $level, 'body' => $line, 'attributes' => []];
    }

    /**
     * The trace and span ids of the active span, lowercase hex; null outside one.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function activeIds(): ?array
    {
        $context = \OpenTelemetry\API\Trace\Span::getCurrent()->getContext();

        return $context->isValid() ? [strtolower($context->getTraceId()), strtolower($context->getSpanId())] : null;
    }

    /**
     * The request's ids on each complete line of $text (each line followed by a newline; with
     * $last, the final line too): ` trace_id=<32 hex> span_id=<16 hex>` at the end of a text line,
     * two fields in a JSON one. Lines that are blank, already carry a trace_id or are the SDK's own
     * are left as they are. This is how a line collected from the output (the Zharp agent reading
     * a container's logs) is linked to its request.
     */
    public static function stampText(string $text, string $traceId, string $spanId, bool $last = false): string
    {
        if (!$last && !str_contains($text, "\n")) {
            return $text;
        }
        $parts = explode("\n", $text);
        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            if ($i === $count - 1 && !$last) {
                break;
            }
            $parts[$i] = self::stamp($parts[$i], $traceId, $spanId);
        }

        return implode("\n", $parts);
    }

    /**
     * One formatted record, stamped once at the end of its last line: a multi-line record (a stack
     * trace) is joined into one entry by the agent, which reads the stamp at the end of the entry.
     * Trailing newlines stay after the stamp.
     */
    public static function stampRecord(string $record, string $traceId, string $spanId): string
    {
        $body = rtrim($record, "\r\n");
        $after = substr($record, strlen($body));
        $trimmed = rtrim($body);
        if (trim($trimmed) === '' || str_contains($body, 'trace_id') || str_contains($body, '[Zharp]')) {
            return $record;
        }
        // A whole JSON record gets fields; anything else (a text record whose context JSON ends
        // its last line, too) gets the suffix.
        if (str_starts_with(ltrim($trimmed), '{') && str_ends_with($trimmed, '}') && is_array(json_decode($trimmed, true))) {
            return self::stamp($body, $traceId, $spanId) . $after;
        }

        return $trimmed . ' trace_id=' . $traceId . ' span_id=' . $spanId . substr($body, strlen($trimmed)) . $after;
    }

    /** One line, stamped (see stampText). */
    public static function stamp(string $line, string $traceId, string $spanId): string
    {
        $cr = str_ends_with($line, "\r") ? "\r" : '';
        $body = $cr === '' ? $line : substr($line, 0, -1);
        $trimmed = rtrim($body);
        if (trim($trimmed) === '' || str_contains($body, 'trace_id') || str_contains($body, '[Zharp]')) {
            return $line;
        }
        $tail = substr($body, strlen($trimmed));
        if (str_ends_with($trimmed, '}')) {
            // A JSON record gets fields; the tail of a record written in pieces is left as it is.
            if (!str_starts_with(ltrim($trimmed), '{')) {
                return $line;
            }
            $open = strpos($trimmed, '{');
            $close = strrpos($trimmed, '}');
            $inner = trim(substr($trimmed, $open + 1, $close - $open - 1));
            $fields = '"trace_id":"' . $traceId . '","span_id":"' . $spanId . '"';

            return substr($trimmed, 0, $close) . ($inner !== '' ? ',' : '') . $fields . '}' . $tail . $cr;
        }

        return $trimmed . ' trace_id=' . $traceId . ' span_id=' . $spanId . $tail . $cr;
    }

    /** @return array{0: int, 1: string}|null */
    private static function levelOf(mixed $value): ?array
    {
        if (is_int($value) || is_float($value)) {
            foreach ($value >= 100 ? self::MONOLOG : self::PINO as $floor => $name) {
                if ($value >= $floor) {
                    return self::LEVELS[$name];
                }
            }

            return null;
        }

        return is_string($value) ? (self::LEVELS[strtolower($value)] ?? null) : null;
    }
}
