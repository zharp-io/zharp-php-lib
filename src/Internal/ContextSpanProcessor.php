<?php

declare(strict_types=1);

namespace Zharp\Internal;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use Throwable;
use Zharp\Zharp;

/**
 * Puts the user and session (from the baggage a Zharp front end sends, or Zharp::setUser) and
 * the tags on every span as it starts.
 *
 * @internal
 */
final class ContextSpanProcessor implements SpanProcessorInterface
{
    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        try {
            $span->setAttributes(Zharp::contextAttributes($parentContext));
        } catch (Throwable) {
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
