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
            $parts[] = ($depth > 0 ? 'Caused by: ' : '')
                . get_class($current) . ': ' . self::message($current) . "\n"
                . self::frames($current);
        }

        return implode("\n", $parts);
    }

    /**
     * The stack innermost first, each line naming the function it is IN — "#0 app.php(12):
     * price_with_tax()" — the way every other language's stack reads, and what the error page
     * shows next to that line's code. getTraceAsString names the function CALLED on each line
     * instead, one frame off: the throw site would show no function and price_with_tax() would sit
     * on its caller's line.
     *
     * Without the arguments: PHP prints them unless zend.exception_ignore_args is on, and they are
     * the app's data — an email passed to create(), a password to login().
     */
    private static function frames(Throwable $e): string
    {
        $trace = $e->getTrace();
        $call = static fn (?array $frame): string => $frame === null
            ? '{main}'
            : ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        $where = static fn (array $frame): string => isset($frame['file'])
            ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')'
            : '[internal function]';

        $lines = [];
        // An error turned into an exception (ErrorException, thrown by an error handler): PHP
        // called the handler from the error's own line, so the trace starts with the handler's
        // frames — Laravel's goes through a closure first. The frame at the error's line is where
        // it happened; the handler's frames above it are dropped.
        if ($e instanceof \ErrorException) {
            foreach (array_slice($trace, 0, 5, true) as $i => $frame) {
                if (($frame['file'] ?? null) === $e->getFile() && ($frame['line'] ?? null) === $e->getLine()) {
                    $trace = array_slice($trace, $i);
                    break;
                }
            }
        }
        $first = $trace[0] ?? null;
        $handlerAtSite = $first !== null && ($first['file'] ?? null) === $e->getFile() && ($first['line'] ?? null) === $e->getLine();
        // Else the throw site, inside the first call of the trace.
        if (!$handlerAtSite) {
            $lines[] = $e->getFile() . '(' . $e->getLine() . '): ' . $call($first);
        }
        foreach ($trace as $i => $frame) {
            $lines[] = $where($frame) . ': ' . $call($trace[$i + 1] ?? null);
        }

        return implode("\n", array_map(static fn (string $line, int $n): string => '#' . $n . ' ' . $line, $lines, array_keys($lines)));
    }
}
