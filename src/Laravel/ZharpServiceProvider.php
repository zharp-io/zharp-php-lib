<?php

declare(strict_types=1);

namespace Zharp\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Zharp\Zharp;

/**
 * Zharp for Laravel, found by package discovery — set ZHARP_KEY (and ZHARP_SERVICE,
 * ZHARP_ENVIRONMENT) in .env and that's all:
 *
 * - every HTTP request gets a SERVER span (the TraceRequests global middleware);
 * - exceptions Laravel reports become unhandled Zharp errors, with the request's trace;
 * - everything Laravel logs (info and up by default) is sent with the trace, user and session;
 * - telemetry is sent once each response is out, and after every queue job.
 */
class ZharpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/zharp.php', 'zharp');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/zharp.php' => $this->app->configPath('zharp.php')], 'zharp-config');
        }

        $config = (array) $this->app['config']->get('zharp', []);
        $started = Zharp::init([
            'key' => $config['key'] ?? null,
            'service' => $config['service'] ?? null,
            'environment' => $config['environment'] ?? null,
            'release' => $config['release'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            'sample_rate' => $config['sample_rate'] ?? null,
            // Laravel's exception handler reports uncaught exceptions; Zharp listens there.
            'capture_uncaught' => false,
            'quiet' => true,
        ]);
        if (!$started) {
            return;
        }

        if (filter_var($config['trace_requests'] ?? true, FILTER_VALIDATE_BOOL)) {
            $this->traceRequests();
        }
        $this->reportExceptions();
        $this->bridgeLogs($config['log_level'] ?? 'info');
        $this->flushAfterWork();
    }

    private function traceRequests(): void
    {
        $add = static function ($kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(TraceRequests::class);
            }
        };
        // Laravel 11+ has resolved the kernel before providers boot; older versions haven't yet.
        if ($this->app->resolved(HttpKernel::class)) {
            $add($this->app->make(HttpKernel::class));
        } else {
            $this->app->afterResolving(HttpKernel::class, $add);
        }
    }

    private function reportExceptions(): void
    {
        $register = static function ($handler): void {
            if (!method_exists($handler, 'reportable')) {
                return;
            }
            // Runs before Laravel logs the exception; that log line keeps its trace but isn't
            // reported a second time.
            $handler->reportable(static function (Throwable $e): void {
                $span = TraceRequests::current();
                if ($span !== null) {
                    $span->recordException($e);
                } else {
                    Zharp::reportException($e, false);
                }
            });
        };
        if ($this->app->resolved(ExceptionHandler::class)) {
            $register($this->app->make(ExceptionHandler::class));
        } else {
            $this->app->afterResolving(ExceptionHandler::class, $register);
        }
    }

    private function bridgeLogs(mixed $level): void
    {
        if ($level === false || in_array(strtolower((string) $level), ['', 'false', 'off', 'none', '0'], true)) {
            return;
        }
        $minimum = Zharp::SEVERITY[strtolower((string) $level)] ?? 9;
        $this->app['events']->listen(MessageLogged::class, static function (MessageLogged $event) use ($minimum): void {
            $level = strtolower((string) $event->level);
            $severity = Zharp::SEVERITY[$level] ?? 9;
            if ($severity < $minimum) {
                return;
            }
            $context = $event->context;
            $exception = $context['exception'] ?? null;
            unset($context['exception']);
            Zharp::emit(
                $severity,
                $level,
                is_string($event->message) ? $event->message : (string) json_encode($event->message),
                $context,
                $exception instanceof Throwable ? $exception : null,
                true,
            );
        });
    }

    private function flushAfterWork(): void
    {
        // After the response is out (PHP-FPM) — and, in queue workers and Octane, after every job
        // and request, since the process doesn't end between them.
        $this->app->terminating(static function (): void {
            Zharp::flush();
        });
        $flush = static function (): void {
            Zharp::flush();
        };
        foreach ([
            'Illuminate\Queue\Events\JobProcessed',
            'Illuminate\Queue\Events\JobFailed',
            'Laravel\Octane\Events\RequestTerminated',
            'Laravel\Octane\Events\TaskTerminated',
        ] as $event) {
            $this->app['events']->listen($event, $flush);
        }
    }
}
