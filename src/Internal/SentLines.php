<?php

declare(strict_types=1);

namespace Zharp\Internal;

/**
 * What the log captures share, so a line is sent once however many of them see it.
 *
 * The exact captures (Zharp::log, the Monolog handler, Laravel's log events, PHP warnings) send a
 * line with its real level; the output capture (OutputCapture) is the net under them, for what a
 * CLI process prints some other way. While Zharp is sending, the output capture stands aside; and
 * a line one of the exact captures sent a moment ago is recognised when it then reaches the
 * output (a Monolog handler writing to php://output, Symfony's ConsoleHandler, a PHP warning
 * shown with display_errors).
 *
 * @internal
 */
final class SentLines
{
    /** Bodies sent in the last few seconds, by a capture other than the output's. */
    private const RECENT_SECONDS = 3.0;
    private const RECENT_MAX = 500;

    /** @var list<array{0: float, 1: string}> */
    private static array $recent = [];
    private static int $depth = 0;

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function quiet(callable $work): mixed
    {
        self::$depth++;
        try {
            return $work();
        } finally {
            self::$depth--;
        }
    }

    /** Above zero while Zharp is sending: what's printed then is not the app's. */
    public static function isQuiet(): bool
    {
        return self::$depth > 0;
    }

    public static function remember(mixed $body): void
    {
        if (!is_string($body)) {
            return;
        }
        $text = self::normalise($body);
        if (strlen($text) < 4) {
            return;
        }
        self::$recent[] = [microtime(true), $text];
        if (count(self::$recent) > self::RECENT_MAX) {
            self::$recent = array_slice(self::$recent, -self::RECENT_MAX);
        }
    }

    /** Whether a printed line is one a capture already sent: its text contains a recent body. */
    public static function alreadySent(string $line): bool
    {
        $now = microtime(true);
        while (self::$recent !== [] && $now - self::$recent[0][0] > self::RECENT_SECONDS) {
            array_shift(self::$recent);
        }
        if (self::$recent === []) {
            return false;
        }
        $text = self::normalise($line);
        for ($i = count(self::$recent) - 1; $i >= 0; $i--) {
            if (str_contains($text, self::$recent[$i][1])) {
                array_splice(self::$recent, $i, 1);

                return true;
            }
        }

        return false;
    }

    public static function reset(): void
    {
        self::$recent = [];
        self::$depth = 0;
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
