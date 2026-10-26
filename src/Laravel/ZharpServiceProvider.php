<?php

namespace Zharp\Logs\Laravel;

use Illuminate\Support\ServiceProvider;
use Zharp\Logs\Monolog\ZharpHandler;
use Zharp\Logs\Zharp;

/**
 * Zharp for Laravel, found by package discovery — nothing to register.
 *
 * Starts Zharp from config/zharp.php (or ZHARP_* in .env), and adds a "zharp" log channel. Put it
 * in your log stack and everything Laravel logs goes to Zharp, reported exceptions included, with
 * their stack:
 *
 *     LOG_STACK=single,zharp      # Laravel 11+, in .env
 *
 * Laravel handles uncaught exceptions itself and reports them through the log, so Zharp's own
 * handler stays out of its way here.
 */
class ZharpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/zharp.php', 'zharp');

        // The channel, unless the app defines its own "zharp" channel in config/logging.php.
        $config = $this->app['config'];
        if (!$config->has('logging.channels.zharp')) {
            $config->set('logging.channels.zharp', [
                'driver' => 'monolog',
                'handler' => ZharpHandler::class,
                'level' => $config->get('zharp.level', 'debug'),
            ]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/zharp.php' => config_path('zharp.php')], 'zharp-config');
            $this->commands([TestCommand::class]);
        }

        $config = (array) $this->app['config']->get('zharp', []);
        Zharp::init([
            'api_key' => $config['api_key'] ?? null,
            'service' => $config['service'] ?? null,
            'environment' => $config['environment'] ?? null,
            'app_version' => $config['app_version'] ?? null,
            'source' => $config['source'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            // Laravel's own handler reports uncaught exceptions through the log channel.
            'capture_exceptions' => false,
            // Not a line on every artisan command; `php artisan zharp:test` says whether it works.
            'quiet' => true,
        ]);
        if (!Zharp::isActive()) {
            return;
        }

        // Send once the response is out — and, in a queue worker or under Octane, after every job
        // and request, since the process doesn't end between them.
        $this->app->terminating(static function (): void {
            Zharp::flush();
        });
        $events = $this->app['events'];
        $flush = static function (): void {
            Zharp::flush();
        };
        foreach ([
            'Illuminate\Queue\Events\JobProcessed',
            'Illuminate\Queue\Events\JobFailed',
            'Illuminate\Queue\Events\JobExceptionOccurred',
            'Laravel\Octane\Events\RequestTerminated',
            'Laravel\Octane\Events\TaskTerminated',
        ] as $event) {
            $events->listen($event, $flush);
        }
    }
}
