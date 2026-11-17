<?php

declare(strict_types=1);

namespace Zharp\Laravel;

use Zharp\Monolog\LogStamping;

/**
 * A Laravel log channel tap (added to every channel by ZharpServiceProvider): lines the channel
 * writes during a request end with the request's trace_id and span_id.
 */
final class StampLogs
{
    public function __invoke(object $logger): void
    {
        LogStamping::apply($logger);
    }
}
