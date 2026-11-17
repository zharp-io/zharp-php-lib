<?php

declare(strict_types=1);

namespace Zharp\Monolog;

use Monolog\Formatter\FormatterInterface;
use Throwable;
use Zharp\Internal\LogLine;
use Zharp\Zharp;

/**
 * Wraps a handler's formatter (Monolog 2 and 3) so each record written while a span is active ends
 * with ` trace_id=<32 hex> span_id=<16 hex>` — once, after its last line, so a multi-line record
 * such as a stack trace carries one stamp — or, from a JSON formatter, carries `trace_id` and
 * `span_id` fields. Zharp then links a line the agent collects from a file or the container's
 * output to its request. Lines outside a request, lines that already carry a trace_id, and
 * formatters that don't produce text are left as they are. Added by LogStamping.
 */
final class StampingFormatter implements FormatterInterface
{
    public function __construct(private readonly FormatterInterface $inner)
    {
    }

    public function inner(): FormatterInterface
    {
        return $this->inner;
    }

    /** @param mixed $record */
    public function format($record)
    {
        return $this->stamp($this->inner->format($record));
    }

    /** @param array<mixed> $records */
    public function formatBatch(array $records)
    {
        // A batch is its records one after another: each stamped at its own end.
        $batch = $this->inner->formatBatch($records);
        if (!is_string($batch) || !Zharp::stampsLogs() || LogLine::activeIds() === null) {
            return $batch;
        }
        $out = '';
        foreach ($records as $record) {
            $formatted = $this->format($record);
            if (!is_string($formatted)) {
                return $batch;
            }
            $out .= $formatted;
        }

        return $out;
    }

    /** One stamp per record, at the end of its last line (a stack trace is one entry). */
    private function stamp(mixed $formatted): mixed
    {
        if (!is_string($formatted) || $formatted === '' || !Zharp::stampsLogs()) {
            return $formatted;
        }
        try {
            $ids = LogLine::activeIds();

            return $ids === null ? $formatted : LogLine::stampRecord($formatted, $ids[0], $ids[1]);
        } catch (Throwable) {
            return $formatted;
        }
    }
}
