<?php

declare(strict_types=1);

namespace Zharp\Internal;

use Throwable;
use Zharp\Zharp;

/**
 * PHP's warnings, notices and deprecations as logs, with the trace they happened in. Plain PHP
 * only: Laravel and Symfony already turn them into logs or exceptions that Zharp receives.
 *
 * The error handler chains: the one set before still runs, gets the same arguments, and decides
 * as before whether PHP shows or logs the error. What error_reporting() leaves out (and what `@`
 * silences) is not sent. Fatal errors are left to the shutdown handler, which reports them as
 * errors.
 *
 * @internal
 */
final class PhpErrors
{
    private const LEVELS = [
        E_WARNING => ['Warning', 13, 'warn', 'E_WARNING'],
        E_USER_WARNING => ['Warning', 13, 'warn', 'E_USER_WARNING'],
        E_NOTICE => ['Notice', 10, 'notice', 'E_NOTICE'],
        E_USER_NOTICE => ['Notice', 10, 'notice', 'E_USER_NOTICE'],
        E_DEPRECATED => ['Deprecated', 13, 'warn', 'E_DEPRECATED'],
        E_USER_DEPRECATED => ['Deprecated', 13, 'warn', 'E_USER_DEPRECATED'],
    ];

    private static bool $installed = false;
    private static bool $enabled = false;
    private static bool $inside = false;
    /** @var callable|null */
    private static $previous = null;
    private static ?\Closure $handler = null;

    public static function install(): void
    {
        self::$enabled = true;
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        self::$handler = static function (int $type, string $message, string $file = '', int $line = 0): mixed {
            self::capture($type, $message, $file, $line);
            $previous = self::$previous;
            if (is_callable($previous)) {
                return $previous(...func_get_args());
            }

            // PHP shows / logs it as it would have.
            return false;
        };
        self::$previous = set_error_handler(self::$handler);
    }

    /** Stop sending; the handler is removed if nothing was set after it, else it just passes on. */
    public static function uninstall(): void
    {
        self::$enabled = false;
        if (!self::$installed) {
            return;
        }
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        if ($current === self::$handler) {
            restore_error_handler();
            self::$installed = false;
            self::$previous = null;
            self::$handler = null;
        }
    }

    private static function capture(int $type, string $message, string $file, int $line): void
    {
        if (!self::$enabled || self::$inside || !isset(self::LEVELS[$type]) || !(error_reporting() & $type)) {
            return;
        }
        self::$inside = true;
        try {
            [$label, $severity, $text, $name] = self::LEVELS[$type];
            Zharp::emit($severity, $text, "{$label}: {$message}", array_filter([
                'php.error.type' => $name,
                'code.filepath' => $file,
                'code.lineno' => $line > 0 ? $line : null,
            ], static fn ($v) => $v !== null && $v !== ''));
        } catch (Throwable) {
        } finally {
            self::$inside = false;
        }
    }
}
