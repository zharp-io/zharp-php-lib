<?php

declare(strict_types=1);

namespace Zharp\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Zharp\Monolog\ZharpHandler;
use Zharp\Zharp;

/**
 * Zharp for Symfony. Enable it in config/bundles.php and set ZHARP_KEY (and ZHARP_SERVICE,
 * ZHARP_ENVIRONMENT) in .env:
 *
 *     Zharp\Symfony\ZharpBundle::class => ['all' => true],
 *
 * Every main request gets a SERVER span (RequestSubscriber); an exception that escapes a
 * controller is an unhandled Zharp error; with MonologBundle, every channel's logs are sent
 * (ZHARP_LOG_LEVEL, info by default) with the trace, user and session.
 */
final class ZharpBundle extends Bundle implements CompilerPassInterface
{
    public function build(ContainerBuilder $container): void
    {
        $container->register(RequestSubscriber::class, RequestSubscriber::class)
            ->addTag('kernel.event_subscriber');
        $container->register('zharp.monolog_handler', ZharpHandler::class)
            ->setFactory([ZharpHandler::class, 'fromEnvironment']);
        // After MonologBundle has defined its loggers, before child definitions are resolved.
        $container->addCompilerPass($this, PassConfig::TYPE_BEFORE_OPTIMIZATION, -100);
    }

    /** Adds Zharp's handler to every Monolog logger. */
    public function process(ContainerBuilder $container): void
    {
        $handler = new Reference('zharp.monolog_handler');
        // Every channel's logger is a child of the prototype and inherits its calls.
        if ($container->hasDefinition('monolog.logger_prototype')) {
            $container->getDefinition('monolog.logger_prototype')->addMethodCall('pushHandler', [$handler]);

            return;
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($id === 'monolog.logger' || str_starts_with($id, 'monolog.logger.')) {
                $definition->addMethodCall('pushHandler', [$handler]);
            }
        }
    }

    public function boot(): void
    {
        // Symfony reports escaped exceptions through kernel.exception; RequestSubscriber listens.
        Zharp::init(['capture_uncaught' => false, 'quiet' => true]);
    }
}
