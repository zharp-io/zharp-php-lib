<?php

declare(strict_types=1);

namespace Zharp\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zharp\Monolog\LogStamping;
use Zharp\Monolog\ZharpHandler;
use Zharp\Zharp;

/**
 * Zharp for Symfony. Enable it in config/bundles.php and set ZHARP_KEY (and ZHARP_SERVICE,
 * ZHARP_ENVIRONMENT) in .env:
 *
 *     Zharp\Symfony\ZharpBundle::class => ['all' => true],
 *
 * Every main request gets a SERVER span (RequestSubscriber); an exception that escapes a
 * controller is an unhandled Zharp error; with MonologBundle, every line a channel writes during
 * a request ends with its trace_id and span_id (ZHARP_LOG_CORRELATION). With ZHARP_LOGS=true,
 * every channel's logs are sent too (ZHARP_LOG_LEVEL, info by default), and what console commands
 * print (ZHARP_CAPTURE_OUTPUT).
 */
final class ZharpBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        $container->register(RequestSubscriber::class, RequestSubscriber::class)
            ->addTag('kernel.event_subscriber');
        // What console commands print (bin/console, messenger:consume …), with symfony/console.
        if (class_exists('Symfony\Component\Console\ConsoleEvents')) {
            $container->register(ConsoleSubscriber::class, ConsoleSubscriber::class)
                ->addTag('kernel.event_subscriber');
        }
        $container->register('zharp.monolog_handler', ZharpHandler::class)
            ->setFactory([ZharpHandler::class, 'fromEnvironment']);
        // After MonologBundle has defined its loggers, before child definitions are resolved.
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
    }

    /**
     * Adds Zharp's handler to every Monolog logger, and stamps each logger's lines with the
     * request's ids (LogStamping, run as the logger's configurator once its handlers are in).
     */
    public function process(ContainerBuilder $container): void
    {
        $handler = new Reference('zharp.monolog_handler');
        $hasPrototype = $container->hasDefinition('monolog.logger_prototype');
        // Every channel's logger is a child of the prototype and inherits its calls.
        if ($hasPrototype) {
            $container->getDefinition('monolog.logger_prototype')->addMethodCall('pushHandler', [$handler]);
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($id !== 'monolog.logger' && $id !== 'monolog.logger_prototype' && !str_starts_with($id, 'monolog.logger.')) {
                continue;
            }
            if (!$hasPrototype) {
                $definition->addMethodCall('pushHandler', [$handler]);
            }
            if ($definition->getConfigurator() === null) {
                $definition->setConfigurator([LogStamping::class, 'configure']);
            }
        }
    }

    public function boot(): void
    {
        // Symfony reports escaped exceptions through kernel.exception; RequestSubscriber listens.
        Zharp::init(['capture_uncaught' => false, 'quiet' => true]);
    }
}
