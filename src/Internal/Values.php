<?php

declare(strict_types=1);

namespace Zharp\Internal;

use Stringable;
use Throwable;

/** @internal */
final class Values
{
    /**
     * Log/span attributes from app data: scalars and lists of scalars kept, everything else as
     * text (OpenTelemetry drops values it can't send).
     *
     * @param array<array-key, mixed> $attributes
     * @return array<string, bool|int|float|string|list<bool|int|float|string>>
     */
    public static function attributes(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = self::value($value);
        }

        return $out;
    }

    /** @return bool|int|float|string|list<bool|int|float|string> */
    private static function value(mixed $value): bool|int|float|string|array
    {
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value) && $value === array_filter($value, 'is_scalar')) {
            return $value;
        }
        if ($value instanceof Throwable) {
            return get_class($value) . ': ' . $value->getMessage();
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_RFC3339_EXTENDED);
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : get_debug_type($value);
    }
}
