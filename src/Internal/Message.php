<?php

namespace Zharp\Logs\Internal;

/**
 * Turning what callers log into the JSON Zharp takes.
 *
 * @internal
 */
final class Message
{
    /** Nested values deeper than this become text, so a structure that contains itself can't hang. */
    private const MAX_DEPTH = 12;

    /**
     * A log message as text. Callers pass whatever they have — an exception, an array, null from a
     * field that wasn't set; the backend refuses an entry whose message isn't non-empty text.
     *
     * @param mixed $value
     */
    public static function toMessage($value): string
    {
        if ($value === null) {
            $text = '';
        } elseif (is_string($value)) {
            $text = $value;
        } elseif ($value instanceof \Throwable) {
            $text = self::describe($value);
        } elseif (is_array($value)) {
            $text = (string) json_encode(self::toJson($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        } elseif (is_bool($value)) {
            $text = $value ? 'true' : 'false';
        } elseif (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $text = (string) $value;
        } else {
            $text = (string) json_encode(self::toJson($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return trim($text) === '' ? '(empty message)' : $text;
    }

    /** "InvalidArgumentException: card declined" — the type people recognise, then what it says. */
    public static function describe(\Throwable $error): string
    {
        $name = self::shortName($error);
        $message = $error->getMessage();

        return trim($message) === '' ? $name : $name . ': ' . $message;
    }

    /** The class without its namespace. */
    public static function shortName(\Throwable $error): string
    {
        $class = get_class($error);
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }

    /** Where the error was thrown, as PHP prints it, with every previous exception in the chain. */
    public static function stackOf(\Throwable $error): string
    {
        $parts = [];
        $seen = 0;
        for ($e = $error; $e !== null && $seen < 10; $e = $e->getPrevious(), $seen++) {
            $parts[] = sprintf(
                "%s%s: %s in %s:%d\nStack trace:\n%s",
                $seen === 0 ? '' : "Previous exception:\n",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
        }

        return implode("\n\n", $parts);
    }

    /**
     * An error as plain fields: its type, its text and where it was thrown.
     *
     * @return array<string, string>
     */
    public static function errorFields(\Throwable $error): array
    {
        return ['name' => get_class($error), 'message' => self::describe($error), 'stack' => self::stackOf($error)];
    }

    /**
     * The first Throwable among the context's values. PSR-3 says it belongs under 'exception'; it's
     * looked for there first.
     *
     * @param array<mixed> $context
     */
    public static function firstError(array $context): ?\Throwable
    {
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            return $context['exception'];
        }
        foreach ($context as $value) {
            if ($value instanceof \Throwable) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Metadata as plain JSON values. Values JSON can't hold become text.
     *
     * @param array<mixed> $fields
     * @return array<string, mixed>
     */
    public static function toJsonMap(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            $out[(string) $key] = self::toJson($value);
        }

        return $out;
    }

    /**
     * A value as something json_encode writes without failing.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function toJson($value, int $depth = 0)
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            // NaN and infinity aren't JSON.
            return is_finite($value) ? $value : (string) $value;
        }
        if (is_string($value)) {
            // Invalid UTF-8 would make the whole batch unencodable.
            return self::validUtf8($value);
        }
        if ($depth > self::MAX_DEPTH) {
            return is_object($value) ? get_class($value) : gettype($value);
        }
        if ($value instanceof \Throwable) {
            return self::errorFields($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::toJson($v, $depth + 1);
            }

            return $out;
        }
        if ($value instanceof \JsonSerializable) {
            try {
                return self::toJson($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable $e) {
                return get_class($value);
            }
        }
        if (is_object($value)) {
            if (function_exists('enum_exists') && $value instanceof \UnitEnum) {
                return $value instanceof \BackedEnum ? $value->value : $value->name;
            }
            if (method_exists($value, '__toString')) {
                return self::validUtf8((string) $value);
            }
            if (method_exists($value, 'toArray')) {
                try {
                    return self::toJson($value->toArray(), $depth + 1);
                } catch (\Throwable $e) {
                    return get_class($value);
                }
            }

            return self::toJson(get_object_vars($value), $depth + 1) ?: get_class($value);
        }
        if (is_resource($value)) {
            return 'resource(' . get_resource_type($value) . ')';
        }

        return gettype($value);
    }

    /** "2026-10-26T09:15:02.123Z" */
    public static function iso(float $timestamp): string
    {
        $seconds = (int) floor($timestamp);
        $millis = (int) floor(($timestamp - $seconds) * 1000);

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }

    private static function validUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        return function_exists('mb_convert_encoding')
            ? (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8')
            : (string) preg_replace('/[^\x20-\x7E\t\r\n]/', '?', $value);
    }
}
