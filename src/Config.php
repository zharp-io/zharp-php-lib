<?php

declare(strict_types=1);

namespace Zharp;

/**
 * Zharp's settings: options passed to Zharp::init() first, then ZHARP_* environment variables.
 *
 * @internal
 */
final class Config
{
    public const DEFAULT_ENDPOINT = 'https://api.zharp.io/api/v1/otlp';

    private function __construct(
        public readonly string $key,
        public readonly string $service,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly string $endpoint,
        public readonly float $sampleRate,
        public readonly bool $captureUncaught,
        public readonly bool $tracing,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function resolve(array $options): ?self
    {
        $key = self::first($options['key'] ?? null, self::env('ZHARP_KEY'), self::env('ZHARP_API_KEY'));
        if ($key === null) {
            return null;
        }
        $rate = $options['sample_rate'] ?? self::env('ZHARP_SAMPLE_RATE');
        $rate = is_numeric($rate) ? (float) $rate : 1.0;

        return new self(
            $key,
            self::first($options['service'] ?? null, self::env('ZHARP_SERVICE'), self::env('OTEL_SERVICE_NAME')) ?? 'unknown-service',
            self::first($options['environment'] ?? null, self::env('ZHARP_ENVIRONMENT'), self::env('ZHARP_ENV')),
            self::first($options['release'] ?? null, self::env('ZHARP_RELEASE'), self::env('ZHARP_VERSION')),
            rtrim(self::first($options['endpoint'] ?? null, self::env('ZHARP_ENDPOINT')) ?? self::DEFAULT_ENDPOINT, '/'),
            ($rate > 0.0 && $rate <= 1.0) ? $rate : 1.0,
            (bool) ($options['capture_uncaught'] ?? true),
            self::flag($options['tracing'] ?? self::env('ZHARP_TRACING'), true),
        );
    }

    /** true/false, 1/0, yes/no, on/off; anything else (or unset) is the default. */
    private static function flag(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    public static function disabled(): bool
    {
        return in_array(strtolower((string) self::env('ZHARP_DISABLED')), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * An environment variable from $_SERVER / $_ENV (where Symfony's Dotenv and Laravel put .env)
     * or getenv(). Null when unset or blank.
     */
    public static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function first(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
