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

    private const ANSI = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';
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
