<?php

declare(strict_types=1);

namespace Zharp\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Zharp\Internal\OutputCapture;
use Zharp\Zharp;

/**
 * Taps a console command's output when it starts (registered by ZharpBundle when symfony/console
 * is installed), so what bin/console commands and workers print is sent as logs, once.
 */
final class ConsoleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // ConsoleEvents::COMMAND, by name: symfony/console is optional.
        return ['console.command' => ['onCommand', 1024]];
    }

    public function onCommand(object $event): void
    {
        if (Zharp::capturesOutput() && method_exists($event, 'getOutput')) {
            OutputCapture::tapConsole($event->getOutput());
        }
    }
}
