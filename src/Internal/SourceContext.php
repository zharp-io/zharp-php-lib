<?php

declare(strict_types=1);

namespace Zharp\Internal;

use Throwable;

/**
 * The code around an error's lines, read on the app's own server and sent with the error: PHP
 * runs its source files, so Zharp shows exactly the code that ran with no repository link. Only
 * the app's own frames (never vendor/), the innermost five, five lines either side, each line
 * capped. Off with capture_source_context => false or ZHARP_CAPTURE_SOURCE_CONTEXT=false.
 *
 * @internal
 */
final class SourceContext
{
    public const ATTRIBUTE = 'zharp.source_context';
    private const AROUND = 5;
    private const MAX_FRAMES = 5;
    private const MAX_LINE = 300;
    private const MAX_BYTES = 12000;
    private const MAX_FILE = 2_000_000;

    public static bool $enabled = true;

    /** @var array<string, list<string>|false> */
    private static array $files = [];

    /** The JSON for an exception's frames, innermost first: [{file, line, pre, code, post}]. */
    public static function of(Throwable $e): ?string
    {
        if (!self::$enabled) {
            return null;
        }
        $frames = [[$e->getFile(), $e->getLine()]];
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = [(string) $frame['file'], (int) $frame['line']];
            }
        }
        $out = [];
        $seen = [];
        foreach ($frames as [$file, $line]) {
            if (count($out) >= self::MAX_FRAMES) {
                break;
            }
            $key = $file . ':' . $line;
            if ($line < 1 || isset($seen[$key]) || !self::isApp($file)) {
                continue;
            }
            $seen[$key] = true;
            $lines = self::lines($file);
            if ($lines === false || !isset($lines[$line - 1])) {
                continue;
            }
            $out[] = [
                'file' => $file,
                'line' => $line,
                'pre' => array_map(self::cut(...), array_slice($lines, max(0, $line - 1 - self::AROUND), min(self::AROUND, $line - 1))),
                'code' => self::cut($lines[$line - 1]),
                'post' => array_map(self::cut(...), array_slice($lines, $line, self::AROUND)),
            ];
        }
        while ($out !== []) {
            $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false && strlen($json) <= self::MAX_BYTES) {
                return $json;
            }
            array_pop($out);
        }

        return null;
    }

    private static function isApp(string $file): bool
    {
        $path = str_replace(chr(92), '/', $file);

        return $path !== '' && !str_contains($path, '/vendor/') && !str_contains($path, '(') && !str_ends_with($path, '.phar') && !str_contains($path, '.phar/');
    }

    /** @return list<string>|false */
    private static function lines(string $file): array|false
    {
        if (!array_key_exists($file, self::$files)) {
            if (count(self::$files) > 50) {
                self::$files = [];
            }
            $size = @filesize($file);
            $text = ($size !== false && $size <= self::MAX_FILE) ? @file_get_contents($file) : false;
            self::$files[$file] = $text === false ? false : preg_split('/\r\n|\n|\r/', $text);
        }

        return self::$files[$file];
    }

    private static function cut(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE) {
            return $line;
        }

        return function_exists('mb_substr') ? mb_substr($line, 0, self::MAX_LINE - 1) . '…' : substr($line, 0, self::MAX_LINE - 1) . '…';
    }
}
