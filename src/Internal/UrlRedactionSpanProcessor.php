<?php

declare(strict_types=1);

namespace Zharp\Internal;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ExtendedSpanProcessorInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use Throwable;

/**
 * Secrets in URLs never leave the process: just before any span ends, query values under
 * secret-looking keys (`token`, `key`, `sig`, `password`, …) in its URL attributes become
 * `[Filtered]`, and `user:password@` is dropped. Every span, whatever made it (the request span,
 * auto-instrumented Guzzle or PDO calls), and whether or not capture_bodies is on.
 *
 * @internal
 */
final class UrlRedactionSpanProcessor implements ExtendedSpanProcessorInterface
{
    private const URL_KEYS = ['url.full', 'http.url', 'http.target'];

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
    }

    public function onEnding(ReadWriteSpanInterface $span): void
    {
        try {
            foreach (self::URL_KEYS as $key) {
                $value = $span->getAttribute($key);
                if (is_string($value)) {
                    $redacted = Bodies::redactUrl($value);
                    if ($redacted !== $value) {
                        $span->setAttribute($key, $redacted);
                    }
                }
            }
            $query = $span->getAttribute('url.query');
            if (is_string($query)) {
                $mark = str_starts_with($query, '?') ? '?' : '';
                $bare = $mark === '' ? $query : substr($query, 1);
                $redacted = Bodies::redactQuery($bare);
                if ($redacted !== $bare) {
                    $span->setAttribute('url.query', $mark . $redacted);
                }
            }
        } catch (Throwable) {
            // Never let telemetry break a request.
        }
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
