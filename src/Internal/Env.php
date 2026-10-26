<?php

namespace Zharp\Logs\Internal;

/**
 * Settings from the environment, and the startup line.
 *
 * @internal
 */
final class Env
{
    /** An environment variable; null when unset or blank. */
    public static function value(string $name): ?string
    {
        // getenv() misses what's only in $_ENV / $_SERVER (phpdotenv, some FPM pools); look there too.
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * The first value that is non-blank text — explicit option, then each fallback in turn.
     *
     * @param mixed ...$values
     */
    public static function pick(...$values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /** @param mixed $value */
    public static function isTrue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && preg_match('/^(true|1|yes|on)$/i', trim((string) $value)) === 1;
    }

    /**
     * The app's own name, from the composer.json above the script that was started — the name a
     * service gets when none is configured: "acme/checkout-api" → "checkout-api".
     */
    public static function projectName(): ?string
    {
        $start = isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])
            ? realpath($_SERVER['SCRIPT_FILENAME'])
            : getcwd();
        if ($start === false || $start === null) {
            return null;
        }
        $dir = is_dir($start) ? $start : dirname($start);
        for ($i = 0; $i < 8; $i++) {
            $file = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($file)) {
                $json = json_decode((string) @file_get_contents($file), true);
                // The first composer.json up the tree is the app's; one without a name ends the search.
                if (is_array($json) && isset($json['name']) && is_string($json['name'])) {
                    $parts = explode('/', $json['name']);

                    return end($parts) ?: null;
                }

                return null;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /** @var bool */
    public static $quiet = false;

    /**
     * Startup output, on stderr, in a script or command only: a web server starts the SDK on every
     * request, and a line per request would bury the error log. One prefix, so it can be searched for.
     */
    public static function announce(string $message): void
    {
        if (self::$quiet || PHP_SAPI !== 'cli' || !defined('STDERR')) {
            return;
        }
        @fwrite(STDERR, '[Zharp] ' . $message . PHP_EOL);
    }
}
