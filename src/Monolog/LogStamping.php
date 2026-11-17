<?php

declare(strict_types=1);

namespace Zharp\Monolog;

use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\HandlerInterface;
use ReflectionProperty;
use Throwable;

/**
 * Puts the request's trace_id and span_id on every line a Monolog logger writes (see
 * StampingFormatter): each handler's formatter is wrapped, including handlers inside groups and
 * wrappers (FingersCrossed, Buffer, Filter, Sampling). Laravel's channels and Symfony's loggers
 * get it from the integrations; for a Monolog logger of your own:
 *
 *     Zharp\Monolog\LogStamping::apply($logger);
 *
 * Safe to call more than once. The stamp follows the `log_correlation` setting at the moment each
 * line is written.
 */
final class LogStamping
{
    /** @param object $logger a Monolog Logger, or anything with getLogger() returning one (Laravel's) */
    public static function apply(object $logger): void
    {
        try {
            if (!method_exists($logger, 'getHandlers') && method_exists($logger, 'getLogger')) {
                $logger = $logger->getLogger();
            }
            if (!is_object($logger) || !method_exists($logger, 'getHandlers')) {
                return;
            }
            foreach ($logger->getHandlers() as $handler) {
                self::handler($handler);
            }
        } catch (Throwable) {
        }
    }

    /** For Symfony's service configurator. */
    public static function configure(object $logger): void
    {
        self::apply($logger);
    }

    private static function handler(mixed $handler, int $depth = 0): void
    {
        if (!$handler instanceof HandlerInterface || $handler instanceof ZharpHandler || $depth > 5) {
            return;
        }
        try {
            if ($handler instanceof GroupHandler) {
                $property = new ReflectionProperty(GroupHandler::class, 'handlers');
                foreach ((array) $property->getValue($handler) as $inner) {
                    self::handler($inner, $depth + 1);
                }

                return;
            }
            if ($handler instanceof FormattableHandlerInterface) {
                // Wrappers (FingersCrossed, Buffer, HandlerWrapper …) pass this on to their handler.
                $formatter = $handler->getFormatter();
                if (!$formatter instanceof StampingFormatter) {
                    $handler->setFormatter(new StampingFormatter($formatter));
                }
            }
        } catch (Throwable) {
            // A handler that can't take a formatter writes its lines unstamped.
        }
    }
}
