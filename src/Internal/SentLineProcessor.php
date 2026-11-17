<?php

declare(strict_types=1);

namespace Zharp\Internal;

use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;
use Throwable;

/**
 * Remembers what every capture but the output's sent — Zharp's own logs and any other
 * OpenTelemetry log bridge in the app — so the same line reaching the output a moment later is not
 * sent twice.
 *
 * @internal
 */
final class SentLineProcessor implements LogRecordProcessorInterface
{
    public function onEmit(ReadWriteLogRecord $record, ?ContextInterface $context = null): void
    {
        try {
            $source = $record->getAttributes()->get('log.source');
            if ($source !== 'stdout' && $source !== 'stderr') {
                SentLines::remember($record->getBody());
            }
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
