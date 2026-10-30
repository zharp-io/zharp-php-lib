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
        return [
            'exception.type' => get_class($e),
            'exception.message' => $e->getMessage(),
            'exception.stacktrace' => self::stacktrace($e),
            'zharp.handled' => $handled ? 'true' : 'false',
        ];
    }

    public static function stacktrace(Throwable $e): string
    {
        $parts = [];
        $depth = 0;
        for ($current = $e; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            $frames = preg_replace_callback(
                '/^#(\d+) /m',
                static fn (array $m): string => '#' . ((int) $m[1] + 1) . ' ',
                $current->getTraceAsString(),
            );
            $parts[] = ($depth > 0 ? 'Caused by: ' : '')
                . get_class($current) . ': ' . $current->getMessage() . "\n"
                . '#0 ' . $current->getFile() . '(' . $current->getLine() . ')' . "\n"
                . $frames;
        }

        return implode("\n", $parts);
    }
}
