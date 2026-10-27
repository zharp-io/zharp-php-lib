<?php

namespace Zharp\Logs;

use Zharp\Logs\Internal\Env;
use Zharp\Logs\Internal\Message;
use Zharp\Logs\Internal\Transport;

/**
 * Sends your PHP app's logs and exceptions to Zharp.
 *
 * Start it once, as early as possible:
 *
 *     Zharp::init(['api_key' => 'your-ingest-only-key', 'service' => 'checkout-api']);
 *     Zharp::info('User signed in', ['user_id' => 42]);
 *
 * PHP has no background threads, so logs are queued and sent when the request (or script) ends —
 * in PHP-FPM after the response has gone to the browser. A long-running process (a queue worker,
 * a daemon) also sends every few seconds, and at once for error and fatal. Logging never throws.
 */
final class Zharp
{
    public const VERSION = '0.1.1';

    /** What requests from this SDK say they are, in the X-Zharp-SDK header. */
    public const SDK_NAME = 'php-logs/' . self::VERSION;

    /** Logs kept while they wait to be sent. When there are more, the oldest go first. */
    public const MAX_QUEUED = 500;

    private const LEVELS = ['debug', 'info', 'warn', 'error', 'fatal'];
    private const ALIASES = [
        'warning' => 'warn', 'notice' => 'info', 'critical' => 'fatal', 'alert' => 'fatal',
        'emergency' => 'fatal', 'trace' => 'debug', 'err' => 'error',
    ];

    /** How long a request may spend sending what's left when it ends (after the response, under FPM). */
    private const END_SEND_SECONDS = 5.0;
    /** How long a crashing script waits for its last logs to go out. */
    private const CRASH_SEND_SECONDS = 2.0;

    /** @var array<string, mixed>|null */
    private static $setup;
    /** @var array<int, array<string, mixed>> */
    private static $queue = [];
    /** @var float */
    private static $lastSent = 0.0;
    /** @var string|null */
    private static $sessionId;
    /** @var array<string, mixed> */
    private static $extraContext = [];
    /** @var array<string, mixed>|null */
    private static $runtime;
    /** @var bool */
    private static $shutdownRegistered = false;
    /** @var bool */
    private static $exceptionHandlerSet = false;
    /** @var callable|null */
    private static $previousExceptionHandler;
    /** @var bool */
    private static $reporting = false;
    /** @var Transport|null For tests: the transport to use instead of making one. */
    private static $transportOverride;

    private function __construct()
    {
    }

    /**
     * Start sending logs. Call once, as early as possible.
     *
     * Anything left out is read from the environment: ZHARP_API_KEY, ZHARP_SERVICE (then
     * OTEL_SERVICE_NAME), ZHARP_ENV, ZHARP_VERSION, ZHARP_SOURCE, ZHARP_ENDPOINT. With no API key
     * anywhere, nothing starts and a hint is written; ZHARP_DISABLED=true turns it off whatever the
     * code says. Never throws.
     *
     * Options: api_key, service, environment, app_version, source, endpoint, flush_interval
     * (seconds, for long-running processes; 5), max_batch_size (50), capture_exceptions (true —
     * uncaught exceptions and fatal errors), quiet (false — true prints nothing at startup).
     *
     * In a script or command it prints one line to stderr:
     * [Zharp] Logging active — service "checkout-api", environment "production".
     * Under a web server it prints nothing, since it starts on every request.
     *
     * @param array<string, mixed> $options
     */
    public static function init(array $options = []): void
    {
        try {
            Env::$quiet = ($options['quiet'] ?? false) === true;
            if (self::$setup !== null) {
                // What the old setup queued is still sent, with its own key.
                self::sendQueued(microtime(true) + self::CRASH_SEND_SECONDS);
            }
            self::$setup = null;

            if (Env::isTrue(Env::value('ZHARP_DISABLED'))) {
                Env::announce('Logging disabled by ZHARP_DISABLED — nothing will be sent');

                return;
            }
            $key = Env::pick($options['api_key'] ?? null, Env::value('ZHARP_API_KEY'));
            if ($key === null) {
                Env::announce("No API key found — pass 'api_key' to Zharp::init(), or set ZHARP_API_KEY");

                return;
            }
            $service = Env::pick($options['service'] ?? null, Env::value('ZHARP_SERVICE'), Env::value('OTEL_SERVICE_NAME'), Env::projectName());
            if ($service === null) {
                // A warning, not an error: a missing name shouldn't stop the app, and the logs are
                // still worth having under a placeholder.
                Env::announce("No service name — pass 'service' to Zharp::init() or set ZHARP_SERVICE. Using 'unknown-service'.");
                $service = 'unknown-service';
            }

            self::$setup = [
                'service' => $service,
                'environment' => Env::pick($options['environment'] ?? null, Env::value('ZHARP_ENV')),
                'app_version' => Env::pick($options['app_version'] ?? null, Env::value('ZHARP_VERSION')),
                'source' => Env::pick($options['source'] ?? null, Env::value('ZHARP_SOURCE')),
                'flush_interval' => max(0.1, (float) ($options['flush_interval'] ?? 5)),
                'max_batch_size' => max(1, (int) ($options['max_batch_size'] ?? 50)),
                'transport' => self::$transportOverride ?? new Transport(
                    $key,
                    Transport::normalizeEndpoint(Env::pick($options['endpoint'] ?? null, Env::value('ZHARP_ENDPOINT')))
                ),
            ];
            self::$lastSent = microtime(true);
            self::registerShutdown();
            if (($options['capture_exceptions'] ?? true) !== false) {
                self::installExceptionHandler();
            }
            self::$setup['capture_fatal'] = ($options['capture_exceptions'] ?? true) !== false;

            $env = self::$setup['environment'] !== null ? sprintf('environment "%s"', self::$setup['environment']) : 'no environment set';
            Env::announce(sprintf('Logging active — service "%s", %s', $service, $env));
        } catch (\Throwable $e) {
            self::$setup = null;
        }
    }

    /** Whether init() succeeded and logs are being sent. */
    public static function isActive(): bool
    {
        return self::$setup !== null;
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function debug($message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function info($message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function warn($message, array $context = []): void
    {
        self::log('warn', $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function warning($message, array $context = []): void
    {
        self::log('warn', $message, $context);
    }

    /**
     * An error; a Throwable as the message, or under 'exception' in the context, brings its stack.
     *
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function error($message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function fatal($message, array $context = []): void
    {
        self::log('fatal', $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function critical($message, array $context = []): void
    {
        self::log('fatal', $message, $context);
    }

    /**
     * Log a caught exception as an error, with its type and stack (previous exceptions included).
     *
     * @param array<mixed> $context
     */
    public static function captureException(\Throwable $error, array $context = []): void
    {
        self::log('error', $error, $context);
    }

    /**
     * Log at any level (PSR-3 names work too: warning, notice, critical…). $service files this one
     * log under another service than the one set in init(). Before init() logs are dropped.
     *
     * @param mixed $message
     * @param array<mixed> $context
     */
    public static function log(string $level, $message, array $context = [], ?string $service = null): void
    {
        if (self::$setup === null) {
            return;
        }
        try {
            $lvl = self::levelOf($level);
            $error = $message instanceof \Throwable ? $message : Message::firstError($context);
            self::push(self::buildEntry($lvl, $message, $context, $error, $service, microtime(true)), $lvl === 'error' || $lvl === 'fatal');
        } catch (\Throwable $e) {
            // Logging never takes the app down.
        }
    }

    /** The ID every log of this request (or script run) carries, so they can be found together. */
    public static function sessionId(): string
    {
        if (self::$sessionId === null) {
            self::$sessionId = self::uuid();
        }

        return self::$sessionId;
    }

    /** Start a new session ID, e.g. per job in a long-running worker. */
    public static function resetSessionId(): void
    {
        self::$sessionId = self::uuid();
    }

    /**
     * Extra fields added to every log's context, e.g. a signed-in user's ID. Replaces what was set
     * before; pass [] to clear it.
     *
     * @param array<mixed> $context
     */
    public static function setContext(array $context): void
    {
        self::$extraContext = Message::toJsonMap($context);
    }

    /**
     * Send everything queued now, waiting at most $timeout seconds. Returns whether all of it went.
     * Normally not needed — it happens when the request ends — but useful at the end of a job.
     */
    public static function flush(float $timeout = 5.0): bool
    {
        if (self::$setup === null) {
            return true;
        }

        return self::sendQueued(microtime(true) + $timeout);
    }

    /** Send everything queued and stop. Logs after this are dropped until init() again. */
    public static function close(float $timeout = 5.0): bool
    {
        $sent = self::flush($timeout);
        self::$setup = null;

        return $sent;
    }

    /**
     * The entry as Zharp takes it — the same shape every Zharp SDK sends.
     *
     * @internal
     * @param mixed $message
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    public static function buildEntry(string $level, $message, array $context, ?\Throwable $error, ?string $service, float $time): array
    {
        $setup = self::$setup ?? ['service' => 'unknown-service', 'environment' => null, 'app_version' => null, 'source' => null];
        $entry = [
            'service' => $service !== null && trim($service) !== '' ? trim($service) : $setup['service'],
            'level' => $level,
            'message' => Message::toMessage($message),
            'timestamp' => Message::iso($time),
        ];
        // The tags the log viewer filters by, top level where the backend reads them; absent rather
        // than empty when not configured.
        if ($setup['environment'] !== null) {
            $entry['environment'] = $setup['environment'];
        }
        if ($setup['app_version'] !== null) {
            $entry['version'] = $setup['app_version'];
        }
        if ($setup['source'] !== null) {
            $entry['source'] = $setup['source'];
        }

        $meta = [];
        if ($error !== null) {
            // Under the keys the log viewer shows; a stack the caller gave wins.
            $meta['name'] = get_class($error);
            $meta['stack'] = Message::stackOf($error);
        }
        foreach (Message::toJsonMap($context) as $key => $value) {
            $meta[$key] = $value;
        }
        $meta['sessionId'] = self::sessionId();
        if ($setup['environment'] !== null) {
            $meta['environment'] = $setup['environment'];
        }
        $runtime = self::runtimeContext();
        foreach (self::$extraContext as $key => $value) {
            $runtime[$key] = $value;
        }
        if ($setup['app_version'] !== null) {
            $runtime['appVersion'] = $setup['app_version'];
        }
        $meta['context'] = $runtime;
        $entry['metadata'] = $meta;

        return $entry;
    }

    /** @internal Maps any level name to one of Zharp's. */
    public static function levelOf(string $level): string
    {
        $name = strtolower(trim($level));
        $name = self::ALIASES[$name] ?? $name;

        return in_array($name, self::LEVELS, true) ? $name : 'info';
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function push(array $entry, bool $urgent): void
    {
        self::$queue[] = $entry;
        if (count(self::$queue) > self::MAX_QUEUED) {
            array_shift(self::$queue);
        }
        // A web request sends once, when it ends. A long-running process can't wait for that.
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $due = $urgent
                || count(self::$queue) >= self::$setup['max_batch_size']
                || microtime(true) - self::$lastSent >= self::$setup['flush_interval'];
            if ($due) {
                self::sendQueued(microtime(true) + self::CRASH_SEND_SECONDS);
            }
        }
    }

    private static function sendQueued(float $deadline): bool
    {
        $setup = self::$setup;
        if ($setup === null || !self::$queue) {
            return true;
        }
        $delivered = true;
        while (self::$queue && microtime(true) < $deadline) {
            $batch = array_splice(self::$queue, 0, $setup['max_batch_size']);
            try {
                if (!$setup['transport']->send($batch, $deadline)) {
                    $delivered = false;
                }
            } catch (\Throwable $e) {
                $delivered = false;
            }
        }
        self::$lastSent = microtime(true);
        if (self::$queue) {
            // Out of time: what's left is dropped rather than holding the process.
            self::$queue = [];
            $delivered = false;
        }

        return $delivered;
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;
        // Registered from inside a shutdown function, so it runs after the app's own — and sends
        // what they logged too.
        register_shutdown_function(static function (): void {
            register_shutdown_function([self::class, 'onShutdown']);
        });
    }

    /** @internal */
    public static function onShutdown(): void
    {
        if (self::$setup === null) {
            return;
        }
        if (self::$setup['capture_fatal'] ?? false) {
            self::reportFatalError();
        }
        if (!self::$queue) {
            return;
        }
        // Under FPM or LiteSpeed, send after the response has gone: the browser doesn't wait on
        // Zharp. (PHP's built-in server can't, so there a request waits for the send.)
        if (PHP_SAPI !== 'cli') {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
        }
        self::sendQueued(microtime(true) + self::END_SEND_SECONDS);
    }

    /** A fatal error (out of memory, a parse error in an include…) ends the script without an exception. */
    private static function reportFatalError(): void
    {
        $error = error_get_last();
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if ($error === null || ($error['type'] & $fatal) === 0) {
            return;
        }
        // An uncaught exception also ends as a fatal error; it's been sent already.
        if (strpos($error['message'], 'Uncaught ') === 0 && self::$exceptionHandlerSet) {
            return;
        }
        self::log('fatal', $error['message'], [
            'type' => 'fatalError',
            'name' => 'FatalError',
            'stack' => sprintf("PHP Fatal error: %s in %s:%d", $error['message'], $error['file'], $error['line']),
            'file' => $error['file'],
            'line' => $error['line'],
        ]);
    }

    private static function installExceptionHandler(): void
    {
        if (self::$exceptionHandlerSet) {
            return;
        }
        self::$exceptionHandlerSet = true;
        self::$previousExceptionHandler = set_exception_handler([self::class, 'handleException']);
    }

    /**
     * An uncaught exception: sent as fatal with its stack, then handled as it would have been — by
     * the handler that was set before, or as PHP does (reported, and the script ends with code 255).
     *
     * @internal
     */
    public static function handleException(\Throwable $error): void
    {
        if (!self::$reporting) {
            self::$reporting = true;
            try {
                self::log('fatal', $error, ['type' => 'uncaughtException']);
                self::sendQueued(microtime(true) + self::CRASH_SEND_SECONDS);
            } catch (\Throwable $ignored) {
            }
            self::$reporting = false;
        }

        if (self::$previousExceptionHandler !== null) {
            (self::$previousExceptionHandler)($error);

            return;
        }
        $report = sprintf("PHP Fatal error:  Uncaught %s\n  thrown in %s on line %d", (string) $error, $error->getFile(), $error->getLine());
        if (PHP_SAPI === 'cli' && defined('STDERR')) {
            fwrite(STDERR, $report . PHP_EOL);
        } else {
            error_log($report);
            if (!headers_sent()) {
                http_response_code(500);
            }
        }
        exit(255);
    }

    /**
     * What is known about where the program runs. The request's method and path are read per log,
     * since a long-running server handles many.
     *
     * @return array<string, mixed>
     */
    private static function runtimeContext(): array
    {
        if (self::$runtime === null) {
            $host = Env::value('HOSTNAME') ?? (gethostname() ?: null);
            self::$runtime = array_filter([
                'hostname' => $host,
                'phpVersion' => PHP_VERSION,
                'platform' => PHP_OS_FAMILY,
                'sapi' => PHP_SAPI,
            ], static function ($v) {
                return $v !== null;
            });
        }
        $context = self::$runtime;
        $context['pid'] = (string) getmypid();
        if (isset($_SERVER['REQUEST_METHOD']) && PHP_SAPI !== 'cli') {
            $context['method'] = (string) $_SERVER['REQUEST_METHOD'];
            // The path only: a query string can carry tokens.
            $context['path'] = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        }

        return $context;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @internal For tests: start from nothing, sending through $transport. */
    public static function resetForTests(?Transport $transport = null): void
    {
        self::$setup = null;
        self::$queue = [];
        self::$extraContext = [];
        self::$sessionId = null;
        self::$transportOverride = $transport;
    }

    /**
     * @internal For tests: what's waiting to be sent.
     * @return array<int, array<string, mixed>>
     */
    public static function queued(): array
    {
        return self::$queue;
    }
}
