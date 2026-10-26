<?php

namespace Zharp\Logs\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Zharp\Logs\Zharp;

/**
 * A Monolog handler that sends records to Zharp — the log channel for Laravel and Symfony.
 *
 * Works with Monolog 2 and 3. Each record goes at the matching level with its channel, context and
 * extra; an exception under 'exception' in the context (where Laravel and Symfony put reported
 * exceptions) brings its stack. Start Zharp with Zharp::init() first (the Laravel service provider
 * does); records before that are dropped.
 *
 *     $logger->pushHandler(new ZharpHandler());
 */
class ZharpHandler extends AbstractProcessingHandler
{
    /** @var string|null */
    private $service;

    /**
     * @param int|string|mixed $level The lowest level sent: a Monolog level, 'debug' (100) unless given
     * @param string|null $service Files these logs under another service than Zharp::init()'s
     */
    public function __construct($level = 100, bool $bubble = true, ?string $service = null)
    {
        parent::__construct($level, $bubble);
        $this->service = $service;
    }

    /**
     * Untyped, so the same class fits Monolog 2 (an array) and Monolog 3 (a LogRecord, which reads
     * like an array too).
     *
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $record
     */
    protected function write($record): void
    {
        $context = is_array($record['context'] ?? null) ? $record['context'] : [];
        $extra = is_array($record['extra'] ?? null) ? $record['extra'] : [];
        $fields = $context;
        $fields['channel'] = $record['channel'] ?? null;
        if ($extra) {
            $fields['extra'] = $extra;
        }

        // An exception in the context (Laravel and Symfony put it under 'exception') brings its stack.
        Zharp::log(self::levelName((int) ($record['level'] ?? 200)), (string) ($record['message'] ?? ''), $fields, $this->service);
    }

    /** Monolog's numeric levels (the same in 2 and 3) as Zharp's. */
    public static function levelName(int $level): string
    {
        if ($level >= 500) {
            return 'fatal'; // CRITICAL, ALERT, EMERGENCY
        }
        if ($level >= 400) {
            return 'error';
        }
        if ($level >= 300) {
            return 'warn';
        }
        if ($level >= 200) {
            return 'info'; // INFO, NOTICE
        }

        return 'debug';
    }
}
