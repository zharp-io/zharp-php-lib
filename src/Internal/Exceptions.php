<?php

declare(strict_types=1);

namespace Zharp\Internal;

use Throwable;

/** @internal */
final class Exceptions
{
    /**
     * exception.* as Zharp groups them: the class, the message, and the stack in PHP's own
     * format — "Type: message", then "#0 file(line): call" frames, the throw site first — with
     * each previous exception after a "Caused by:" line.
     *
     * @return array<string, string>
     */
    public static function attributes(Throwable $e, bool $handled): array
    {
        $query = DbErrors::isQuery($e);
        $attributes = [
            'exception.type' => $query ? DbErrors::type($e) : get_class($e),
            'exception.message' => self::message($e),
            'exception.stacktrace' => self::stacktrace($e),
            'zharp.handled' => $handled ? 'true' : 'false',
        ];
        if ($query) {
            $attributes += DbErrors::attributes($e);
        }
        $code = SourceContext::of($e);
        if ($code !== null) {
            $attributes[SourceContext::ATTRIBUTE] = $code;
        }

        return $attributes;
    }

    /** The message, without the row's values a failed query's message carries. */
    public static function message(Throwable $e): string
    {
        return DbErrors::isQuery($e) ? DbErrors::message($e->getMessage()) : $e->getMessage();
    }

    /** "Type: message", as a log line's body. */
    public static function title(Throwable $e): string
    {
        $type = DbErrors::isQuery($e) ? DbErrors::type($e) : get_class($e);

        return $type . ': ' . self::message($e);
    }

    public static function stacktrace(Throwable $e): string
    {
        $parts = [];
        $depth = 0;
        for ($current = $e; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            $frames = self::frames($current);
            $parts[] = ($depth > 0 ? 'Caused by: ' : '')
                . get_class($current) . ': ' . self::message($current) . "\n"
                . '#0 ' . $current->getFile() . '(' . $current->getLine() . ')' . "\n"
                . $frames;
        }

        return implode("\n", $parts);
    }

    /**
     * The frames as getTraceAsString writes them, numbered from #1 — without the arguments. PHP
     * prints them unless zend.exception_ignore_args is on, and they are the app's data: an email
     * passed to create(), a password to login().
     */
    private static function frames(Throwable $e): string
    {
        $lines = [];
        $n = 1;
        foreach ($e->getTrace() as $frame) {
            $where = isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')' : '[internal function]';
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $lines[] = '#' . $n++ . ' ' . $where . ': ' . $call . '()';
        }
        $lines[] = '#' . $n . ' {main}';

        return implode("\n", $lines);
    }
}
