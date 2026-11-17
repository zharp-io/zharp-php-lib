<?php

declare(strict_types=1);

namespace Zharp\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Zharp\Internal\OutputCapture;
use Zharp\Monolog\LogStamping;
use Zharp\Zharp;

/**
 * Zharp for Laravel, found by package discovery — set ZHARP_KEY (and ZHARP_SERVICE,
 * ZHARP_ENVIRONMENT) in .env and that's all:
 *
 * - every HTTP request gets a SERVER span (the TraceRequests global middleware);
 * - exceptions Laravel reports become unhandled Zharp errors, with the request's trace;
 * - every line Laravel's log channels write during a request ends with its trace_id and
 *   span_id (log_correlation), so the Zharp agent's copy of the line is linked to the request;
 * - with logs on (ZHARP_LOGS=true; off by default), everything Laravel logs (info and up) is sent
 *   too, and in queue workers, artisan and Octane workers what the process prints (echo, a
 *   command's output), once (capture_output);
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
            'tracing' => $config['tracing'] ?? null,
            'capture_bodies' => $config['capture_bodies'] ?? null,
            'capture_output' => $config['capture_output'] ?? null,
            'logs' => $config['logs'] ?? null,
            'log_correlation' => $config['log_correlation'] ?? null,
            // Laravel's exception handler reports uncaught exceptions; Zharp listens there.
            'capture_uncaught' => false,
            'quiet' => true,
        ]);
        if (!$started) {
            return;
        }

        // With tracing off the middleware still runs: no span, but the caller's traceparent and
        // baggage become current so the request's logs carry its trace_id, user and session.
        if (filter_var($config['trace_requests'] ?? true, FILTER_VALIDATE_BOOL)) {
            $this->traceRequests();
        }
        $this->reportExceptions();
        $this->bridgeLogs($config['log_level'] ?? 'info');
        $this->stampLogChannels();
        $this->captureCommandOutput();
        $this->flushAfterWork();
    }

    /**
     * The request's ids on every line Laravel's channels write: a tap on each configured channel
     * (stacks included), and the channels already made.
     */
    private function stampLogChannels(): void
    {
        if (!Zharp::stampsLogs()) {
            return;
        }
        try {
            $config = $this->app['config'];
            $channels = $config->get('logging.channels');
            if (is_array($channels)) {
                foreach ($channels as $name => $channel) {
                    if (is_array($channel)) {
                        $taps = (array) ($channel['tap'] ?? []);
                        if (!in_array(StampLogs::class, $taps, true)) {
                            $taps[] = StampLogs::class;
                        }
                        $channels[$name]['tap'] = $taps;
                    }
                }
                $config->set('logging.channels', $channels);
            }
            if ($this->app->resolved('log')) {
                $manager = $this->app->make('log');
                if (is_object($manager) && method_exists($manager, 'getChannels')) {
                    foreach ((array) $manager->getChannels() as $logger) {
                        if (is_object($logger)) {
                            LogStamping::apply($logger);
                        }
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    /** What an artisan command prints ($this->info(), the queue worker's job lines …). */
    private function captureCommandOutput(): void
    {
        if (!Zharp::capturesOutput() || !$this->app->runningInConsole()) {
            return;
        }
        $this->app['events']->listen('Illuminate\Console\Events\CommandStarting', static function ($event): void {
            OutputCapture::tapConsole(is_object($event) ? ($event->output ?? null) : null);
        });
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
            // Logs off (the default): only an exception not reported yet goes, as an error.
            if (!Zharp::sendsLogs() && (!$exception instanceof Throwable || Zharp::isReported($exception))) {
                return;
            }
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
