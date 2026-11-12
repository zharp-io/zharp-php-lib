<?php

declare(strict_types=1);

namespace Zharp;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Throwable;
use WeakMap;
use Zharp\Internal\ContextLogProcessor;
use Zharp\Internal\BodySettings;
use Zharp\Internal\ContextSpanProcessor;
use Zharp\Internal\UrlRedactionSpanProcessor;
use Zharp\Internal\Exceptions;
use Zharp\Internal\Values;

/**
 * Zharp Application Monitoring for PHP — a thin layer over the official OpenTelemetry SDK.
 *
 *     Zharp::init(['key' => 'zpk_…', 'service' => 'checkout-api', 'environment' => 'production']);
 *
 * Laravel and Symfony need no code: set ZHARP_KEY (and ZHARP_SERVICE, ZHARP_ENVIRONMENT) and the
 * service provider / ZharpBundle starts it and traces every request. Never throws into the app.
 */
final class Zharp
{
    public const VERSION = '0.3.0';

    private const SCOPE = 'zharp/zharp';

    /** OTLP severity numbers. */
    public const SEVERITY = [
        'trace' => 1, 'debug' => 5, 'info' => 9, 'notice' => 10, 'warn' => 13, 'warning' => 13,
        'error' => 17, 'critical' => 21, 'fatal' => 21, 'alert' => 22, 'emergency' => 23,
    ];

    private static ?Config $config = null;
    private static ?TracerProvider $tracerProvider = null;
    private static ?LoggerProviderInterface $loggerProvider = null;
    private static ?TracerInterface $tracer = null;
    private static ?LoggerInterface $logger = null;
    private static ?TextMapPropagatorInterface $propagator = null;
    /** @var array<string, string>|null */
    private static ?array $user = null;
    /** @var array<string, bool|int|float|string> */
    private static array $tags = [];
    /** @var WeakMap<Throwable, true>|null */
    private static ?WeakMap $reported = null;
    private static bool $shutdownRegistered = false;
    /** @var callable|null */
    private static $previousExceptionHandler = null;
    private static bool $exceptionHandlerSet = false;

    private function __construct()
    {
    }

    /**
     * Start sending traces, logs and errors. Call once, as early as possible.
     *
     * Options (each falls back to the environment variable): key (ZHARP_KEY), service
     * (ZHARP_SERVICE, then OTEL_SERVICE_NAME), environment (ZHARP_ENVIRONMENT), release
     * (ZHARP_RELEASE), endpoint (ZHARP_ENDPOINT), sample_rate (ZHARP_SAMPLE_RATE, 1.0),
     * capture_uncaught (true: an uncaught exception or fatal error is reported), tracing
     * (ZHARP_TRACING, true; false = logs only: logs, errors and context still go out, but no
     * spans are made or sent and OpenTelemetry instrumentation gets no tracer or propagator).
     * For tests: span_exporter / log_exporter replace OTLP and export synchronously.
     * ZHARP_DISABLED=true turns it off. Returns whether it started.
     *
     * @param array<string, mixed> $options
     */
    public static function init(array $options = []): bool
    {
        if (self::$config !== null) {
            return true;
        }
        try {
            if (Config::disabled()) {
                return false;
            }
            $config = Config::resolve($options);
            if ($config === null) {
                if (empty($options['quiet'])) {
                    error_log('[Zharp] No key — set ZHARP_KEY. Nothing is sent.');
                }

                return false;
            }
            self::start($config, $options['span_exporter'] ?? null, $options['log_exporter'] ?? null);

            return true;
        } catch (Throwable $e) {
            error_log('[Zharp] Could not start: ' . $e->getMessage());
            self::$config = null;

            return false;
        }
    }

    private static function start(Config $config, ?SpanExporterInterface $spanExporter, ?LogRecordExporterInterface $logExporter): void
    {
        $resource = ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(Attributes::create(array_filter([
            'service.name' => $config->service,
            'service.version' => $config->release,
            'deployment.environment.name' => $config->environment,
            'telemetry.sdk.name' => 'zharp',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => self::VERSION,
        ], static fn ($v) => $v !== null && $v !== ''))));

        $headers = ['Authorization' => 'Bearer ' . $config->key];
        $clock = self::clock();
        $tracerProvider = null;
        if ($config->tracing) {
            if ($spanExporter !== null) {
                $spanProcessor = new SimpleSpanProcessor($spanExporter);
            } else {
                $transport = (new OtlpHttpTransportFactory())->create($config->endpoint . '/v1/traces', ContentTypes::PROTOBUF, $headers, 'gzip', 5.0);
                $spanProcessor = new BatchSpanProcessor(new SpanExporter($transport), $clock);
            }
            $tracerProvider = new TracerProvider(
                [new ContextSpanProcessor(), new UrlRedactionSpanProcessor(), $spanProcessor],
                new ParentBased(new TraceIdRatioBasedSampler($config->sampleRate)),
                $resource,
            );
        }
        if ($logExporter !== null) {
            $logProcessor = new SimpleLogRecordProcessor($logExporter);
        } else {
            $transport = (new OtlpHttpTransportFactory())->create($config->endpoint . '/v1/logs', ContentTypes::PROTOBUF, $headers, 'gzip', 5.0);
            $logProcessor = new BatchLogRecordProcessor(new LogsExporter($transport), $clock);
        }

        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor(new ContextLogProcessor())
            ->addLogRecordProcessor($logProcessor)
            ->build();
        $propagator = new MultiTextMapPropagator([TraceContextPropagator::getInstance(), BaggagePropagator::getInstance()]);

        self::$config = $config;
        self::$tracerProvider = $tracerProvider;
        self::$loggerProvider = $loggerProvider;
        self::$propagator = $propagator;
        // Logs only: no spans at all. startSpan() just runs its callback; request integrations
        // still extract an incoming traceparent so log lines carry the caller's trace_id.
        self::$tracer = $tracerProvider?->getTracer(self::SCOPE, self::VERSION) ?? NoopTracer::getInstance();
        self::$logger = $loggerProvider->getLogger(self::SCOPE, self::VERSION);
        self::$reported = new WeakMap();

        // OpenTelemetry instrumentation in the app (the opentelemetry extension's auto packages)
        // uses the globals; they take these unless something read them before init.
        if (method_exists(Globals::class, 'registerInitializer')) {
            // Logs only: instrumentation keeps the no-op tracer and propagator, so it makes no
            // spans and injects no traceparent/baggage into outgoing requests.
            Globals::registerInitializer(static fn (Configurator $c): Configurator => $tracerProvider === null
                ? $c->withLoggerProvider($loggerProvider)
                : $c->withTracerProvider($tracerProvider)
                    ->withLoggerProvider($loggerProvider)
                    ->withPropagator($propagator));
        }

        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                self::reportFatalError();
                self::shutdown();
            });
        }
        if ($config->captureUncaught && !self::$exceptionHandlerSet) {
            self::$exceptionHandlerSet = true;
            self::$previousExceptionHandler = set_exception_handler(static function (Throwable $e): void {
                self::reportException($e, false);
                $previous = self::$previousExceptionHandler;
                if (is_callable($previous)) {
                    $previous($e);

                    return;
                }
                // What PHP does with no handler: print it and exit with status 255.
                error_log('PHP Fatal error:  Uncaught ' . $e);
                exit(255);
            });
        }
    }

    public static function isActive(): bool
    {
        return self::$config !== null;
    }

    /** Whether spans are made and sent (false with tracing => false / ZHARP_TRACING=false). */
    /**
     * `capture_bodies` (ZHARP_CAPTURE_BODIES) as resolved, or null when request and response
     * bodies are not captured (the default), tracing is off, or Zharp isn't running.
     *
     * @internal
     */
    public static function bodySettings(): ?BodySettings
    {
        return self::$config !== null && self::$config->tracing ? self::$config->bodies : null;
    }

    public static function isTracing(): bool
    {
        return self::$config !== null && self::$config->tracing;
    }

    // ── Logs ────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    public static function debug(string $message, array $attributes = []): void
    {
        self::log('debug', $message, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function info(string $message, array $attributes = []): void
    {
        self::log('info', $message, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function warn(string $message, array $attributes = []): void
    {
        self::log('warn', $message, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function error(string $message, array $attributes = []): void
    {
        self::log('error', $message, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function fatal(string $message, array $attributes = []): void
    {
        self::log('fatal', $message, $attributes);
    }

    /**
     * A log with the active trace and the request's user and session. An exception under the
     * "exception" key is reported as an error (handled) unless it already was.
     *
     * @param array<string, mixed> $attributes
     */
    public static function log(string $level, string $message, array $attributes = []): void
    {
        $level = strtolower($level);
        $exception = $attributes['exception'] ?? null;
        unset($attributes['exception']);
        self::emit(self::SEVERITY[$level] ?? 9, $level, $message, $attributes, $exception instanceof Throwable ? $exception : null, true);
    }

    /**
     * The one way logs leave: used by the log methods, the Monolog handler and the framework
     * integrations. An exception already reported keeps the log line but not the error.
     *
     * @internal
     * @param array<string, mixed> $attributes
     */
    public static function emit(int $severity, string $severityText, string $body, array $attributes = [], ?Throwable $exception = null, bool $handled = true): void
    {
        $logger = self::$logger;
        if ($logger === null) {
            return;
        }
        try {
            $attrs = Values::attributes($attributes);
            if ($exception !== null && !self::isReported($exception)) {
                self::markReported($exception);
                $attrs += Exceptions::attributes($exception, $handled);
            }
            $attrs += self::contextAttributes(Context::getCurrent());
            $logger->emit(
                (new LogRecord($body))
                    ->setTimestamp((int) (microtime(true) * 1e9))
                    ->setSeverityNumber($severity)
                    ->setSeverityText(strtoupper($severityText === 'warning' ? 'warn' : $severityText))
                    ->setAttributes($attrs)
                    ->setContext(Context::getCurrent())
            );
        } catch (Throwable) {
            // never into the app
        }
    }

    // ── Errors ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Report an exception you caught. It becomes a Zharp issue (handled) with the request's
     * trace, user and session.
     *
     * @param array<string, mixed> $attributes
     */
    public static function captureError(Throwable $error, array $attributes = []): void
    {
        self::emit(17, 'error', get_class($error) . ': ' . $error->getMessage(), $attributes, $error, true);
    }

    /**
     * Report an exception that escaped (handled=false) and mark the current span as failed. What
     * the framework integrations call for an uncaught exception.
     *
     * @internal
     */
    public static function reportException(Throwable $error, bool $handled = false, ?SpanInterface $span = null): void
    {
        $span ??= Span::getCurrent();
        if (!$handled && $span->isRecording()) {
            $span->setStatus(StatusCode::STATUS_ERROR, $error->getMessage());
        }
        self::emit(17, 'error', get_class($error) . ': ' . $error->getMessage(), [], $error, $handled);
    }

    /** @internal */
    public static function isReported(Throwable $error): bool
    {
        return self::$reported !== null && isset(self::$reported[$error]);
    }

    /** @internal */
    public static function markReported(Throwable $error): void
    {
        if (self::$reported !== null) {
            self::$reported[$error] = true;
        }
    }

    private static function reportFatalError(): void
    {
        $error = error_get_last();
        if ($error === null || self::$config === null || !self::$config->captureUncaught
            || !($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {
            return;
        }
        // An uncaught exception also ends as "Uncaught …" here; it was reported already.
        if (str_starts_with($error['message'], 'Uncaught ')) {
            return;
        }
        self::emit(21, 'fatal', 'FatalError: ' . $error['message'], [
            'exception.type' => 'FatalError',
            'exception.message' => $error['message'],
            'exception.stacktrace' => "FatalError: {$error['message']}\n#0 {$error['file']}({$error['line']})",
            'zharp.handled' => 'false',
        ]);
    }

    // ── Context ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The user of the current request (enduser.id …) on its spans, logs and errors from here on.
     * A front end using a Zharp SDK sends its user with each request; this is for when the
     * server knows better. The framework integrations clear it when a request ends.
     */
    public static function setUser(string|int|null $id = null, ?string $email = null, ?string $name = null): void
    {
        $user = array_filter([
            'enduser.id' => $id === null ? null : (string) $id,
            'enduser.email' => $email,
            'enduser.name' => $name,
        ], static fn ($v) => $v !== null && $v !== '');
        self::$user = $user;
        $span = Span::getCurrent();
        if ($span->isRecording() && $user !== []) {
            $span->setAttributes($user);
        }
    }

    public static function clearUser(): void
    {
        self::$user = null;
    }

    /** An attribute on every span and log from now on; null removes it. */
    public static function setTag(string $key, bool|int|float|string|null $value): void
    {
        if ($value === null) {
            unset(self::$tags[$key]);
        } else {
            self::$tags[$key] = $value;
        }
    }

    /**
     * Tags, then user and session from the parent span, the baggage a Zharp front end sent, and
     * setUser (later wins).
     *
     * @internal
     * @return array<string, bool|int|float|string>
     */
    public static function contextAttributes(ContextInterface $context): array
    {
        $out = self::$tags;
        $parent = Span::fromContext($context);
        if ($parent instanceof ReadableSpanInterface) {
            foreach (['enduser.id', 'enduser.email', 'enduser.name', 'session.id'] as $key) {
                $value = $parent->getAttribute($key);
                if (is_string($value) && $value !== '') {
                    $out[$key] = $value;
                }
            }
        }
        $baggage = Baggage::fromContext($context);
        foreach (['enduser.id', 'session.id'] as $key) {
            $value = $baggage->getValue($key);
            if (is_scalar($value) && (string) $value !== '') {
                $out[$key] = (string) $value;
            }
        }
        if (self::$user !== null) {
            unset($out['enduser.id'], $out['enduser.email'], $out['enduser.name']);
            $out = self::$user + $out;
        }

        return $out;
    }

    // ── Spans ───────────────────────────────────────────────────────────────────────────────────

    /**
     * A span around some work. An exception marks it failed and is rethrown (the framework
     * reports it). With tracing off it only runs the work (given the current, non-recording span).
     *
     * @template T
     * @param callable(SpanInterface): T $work
     * @param array<string, mixed> $attributes
     * @return T
     */
    public static function startSpan(string $name, callable $work, array $attributes = []): mixed
    {
        if (self::$config !== null && !self::$config->tracing) {
            return $work(Span::getCurrent());
        }
        $span = self::tracer()->spanBuilder($name)->setAttributes(Values::attributes($attributes))->startSpan();
        $scope = $span->activate();
        try {
            return $work($span);
        } catch (Throwable $e) {
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /** A tracer for spans of your own. */
    public static function tracer(): TracerInterface
    {
        return self::$tracer ?? Globals::tracerProvider()->getTracer(self::SCOPE, self::VERSION);
    }

    /** @internal W3C tracecontext + baggage. */
    public static function propagator(): TextMapPropagatorInterface
    {
        return self::$propagator ?? Globals::propagator();
    }

    // ── Sending ─────────────────────────────────────────────────────────────────────────────────

    /** Send what's queued now — the integrations call it once each response is out. */
    public static function flush(): bool
    {
        $ok = true;
        try {
            $ok = (self::$tracerProvider?->forceFlush() ?? true) && $ok;
            $ok = (self::$loggerProvider?->forceFlush() ?? true) && $ok;
        } catch (Throwable) {
            return false;
        }

        return $ok;
    }

    /** Send what's left and stop. Runs by itself when the script ends. */
    public static function shutdown(): void
    {
        try {
            self::$tracerProvider?->shutdown();
            self::$loggerProvider?->shutdown();
        } catch (Throwable) {
        }
        self::$config = null;
        self::$tracerProvider = null;
        self::$loggerProvider = null;
        self::$tracer = null;
        self::$logger = null;
    }

    /** @internal For tests: forget everything so init() can run again. */
    public static function resetForTests(): void
    {
        self::shutdown();
        self::$user = null;
        self::$tags = [];
        self::$reported = null;
        if (self::$exceptionHandlerSet) {
            restore_exception_handler();
            self::$exceptionHandlerSet = false;
            self::$previousExceptionHandler = null;
        }
    }

    private static function clock(): \OpenTelemetry\API\Common\Time\ClockInterface
    {
        if (class_exists(\OpenTelemetry\API\Common\Time\Clock::class)) {
            return \OpenTelemetry\API\Common\Time\Clock::getDefault();
        }

        /** @phpstan-ignore-next-line older SDKs */
        return \OpenTelemetry\SDK\Common\Time\ClockFactory::getDefault();
    }
}
