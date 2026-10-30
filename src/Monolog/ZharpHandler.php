<?php

declare(strict_types=1);

namespace Zharp\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Throwable;
use Zharp\Config;
use Zharp\Zharp;

/**
 * Sends Monolog records (Monolog 2 and 3) to Zharp as OpenTelemetry logs, with the active trace
 * and the request's user and session. Context and extra become attributes; an exception under
 * the "exception" key is reported as an error unless it already was. Start Zharp first.
 *
 *     $logger->pushHandler(new ZharpHandler('info'));
 *
 * The Symfony bundle adds it to every Monolog channel; Laravel is bridged through its log events
 * instead, so this handler is for Monolog setups of your own.
 */
class ZharpHandler extends AbstractProcessingHandler
{
    /** Monolog level → OTLP severity number. */
    private const SEVERITY = [100 => 5, 200 => 9, 250 => 10, 300 => 13, 400 => 17, 500 => 21, 550 => 22, 600 => 23];

    private bool $enabled = true;

    /**
     * @param int|string|\Monolog\Level $level the lowest level sent
     */
    public function __construct($level = 'debug', bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /** A handler at ZHARP_LOG_LEVEL (info by default; "off" sends nothing). */
    public static function fromEnvironment(): self
    {
        $level = self::levelFromEnvironment();
        $handler = new self($level ?? 'emergency');
        $handler->enabled = $level !== null;

        return $handler;
    }

    /** ZHARP_LOG_LEVEL as a Monolog level name; null when logs are turned off. */
    public static function levelFromEnvironment(): ?string
    {
        $level = strtolower(Config::env('ZHARP_LOG_LEVEL') ?? 'info');
        if (in_array($level, ['off', 'false', 'none', '0'], true)) {
            return null;
        }
        $level = ['warn' => 'warning', 'fatal' => 'critical', 'trace' => 'debug'][$level] ?? $level;

        return in_array($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true) ? $level : 'info';
    }

    /**
     * @param array<string, mixed>|\Monolog\LogRecord $record
     */
    protected function write($record): void
    {
        if (!$this->enabled || !Zharp::isActive()) {
            return;
        }
        // Monolog 3's LogRecord reads like Monolog 2's array.
        $level = (int) $record['level'];
        $context = (array) $record['context'];
        $extra = (array) $record['extra'];
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $attributes = $context;
        foreach ($extra as $key => $value) {
            $attributes['extra.' . $key] = $value;
        }
        $attributes['log.channel'] = (string) $record['channel'];

        $severity = self::SEVERITY[$level] ?? ($level >= 400 ? 17 : 9);
        Zharp::emit(
            $severity,
            strtolower((string) $record['level_name']),
            (string) $record['message'],
            $attributes,
            $exception instanceof Throwable ? $exception : null,
            true,
        );
    }
}
