<?php

declare(strict_types=1);

namespace Zharp\Internal;

use Closure;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;
use Throwable;
use Zharp\Zharp;

/**
 * Puts the user, session and tags on log records that other OpenTelemetry code emits (Zharp's
 * own logs carry them already).
 *
 * The PHP SDK freezes a record's attributes when it is created, before processors run, so they
 * are replaced through the SDK's own property; if a future SDK renames it, this does nothing.
 *
 * @internal
 */
final class ContextLogProcessor implements LogRecordProcessorInterface
{
    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void
    {
        try {
            $existing = $record->getAttributes()->toArray();
            $missing = array_diff_key(Zharp::contextAttributes($context ?? Context::getCurrent()), $existing);
            if ($missing === [] || !property_exists(ReadableLogRecord::class, 'convertedAttributes')) {
                return;
            }
            $replace = function (array $attributes): void {
                /** @var ReadableLogRecord $this */
                $this->convertedAttributes = Attributes::create($attributes);
            };
            Closure::bind($replace, $record, ReadableLogRecord::class)($existing + $missing);
        } catch (Throwable) {
        }
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
