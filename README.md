# zharp/zharp

[Zharp](https://zharp.io) Application Monitoring for PHP, Laravel and Symfony: every request traced,
logs sent with their trace, exceptions grouped into issues. A thin layer over the official
[OpenTelemetry PHP SDK](https://opentelemetry.io/docs/languages/php/) — Zharp configures the OTLP
exporter, resource, W3C tracecontext + baggage propagation and a log bridge, and creates the
request span when nothing else does.

Full documentation: [zharp.io/docs/sdks/php](https://zharp.io/docs/sdks/php)

## Install

```bash
composer require zharp/zharp
```

PHP 8.2+ (what the OpenTelemetry SDK needs). Composer may ask to allow the `php-http/discovery`
and `tbachert/spi` plugins that the OpenTelemetry SDK uses — allow them.

## Laravel

Nothing to register — the service provider is auto-discovered. In `.env`:

```dotenv
ZHARP_KEY=zpk_…
ZHARP_SERVICE=checkout-api
ZHARP_ENVIRONMENT=production
```

Every HTTP request gets a SERVER span named by its route (`GET /orders/{id}`), continuing the
caller's trace. Exceptions Laravel reports become unhandled Zharp errors with the request's trace.
Every line Laravel's log channels write during a request ends with its `trace_id` and `span_id`
(see [Logs](#logs)). Telemetry goes once the response is out, and after every queue job.
`php artisan vendor:publish --tag=zharp-config` publishes `config/zharp.php`.

## Symfony

Same environment variables, then enable the bundle in `config/bundles.php`:

```php
Zharp\Symfony\ZharpBundle::class => ['all' => true],
```

Every main request gets a SERVER span (named by the route name), an exception that escapes a
controller is an unhandled error (4xx `HttpException`s aren't), and with MonologBundle every
channel's lines written during a request carry its ids (see [Logs](#logs)).

## Plain PHP

```php
Zharp\Zharp::init(['key' => 'zpk_…', 'service' => 'checkout-api', 'environment' => 'production']);
```

An uncaught exception or fatal error is reported; `Zharp\RequestSpan::start(...)` makes a request
span for frameworks without an integration. Monolog: `Zharp\Monolog\LogStamping::apply($logger)`
puts the request's ids on its lines; with logs on, `$logger->pushHandler(new Zharp\Monolog\ZharpHandler('info'))`
sends them too.

## API

```php
use Zharp\Zharp;

Zharp::info('Order placed', ['order_id' => 42]);        // debug, info, warn, error, fatal
Zharp::captureError($e, ['order_id' => 42]);            // a caught exception, as a handled issue
Zharp::setUser(id: $user->id, email: $user->email);     // this request's user, when the server knows better
Zharp::setTag('region', 'eu-west');                     // on every span and log after
Zharp::startSpan('render invoice', fn () => render($invoice));
Zharp::flush();                                          // send now
```

A browser or mobile app using a Zharp SDK sends `traceparent` and `baggage` (`enduser.id`,
`session.id`) with its API calls; the request's span and logs carry them with no code.

## No tracing

Errors (and logs, when on), user/session and tags without any tracing:

```php
Zharp\Zharp::init(['key' => 'zpk_…', 'service' => 'checkout-api', 'tracing' => false]);
```

or `ZHARP_TRACING=false` in `.env` (Laravel, Symfony, plain PHP). No spans are made or sent,
`Zharp::startSpan()` just runs its callback, and OpenTelemetry instrumentation gets no tracer or
propagator, so nothing adds `traceparent`/`baggage` to outgoing requests. An incoming
`traceparent` and `baggage` are still read by the Laravel middleware / Symfony subscriber, so a
request's logs and errors keep the caller's trace id, user and session.

## Logs

**How logs reach Zharp.** Run the Zharp agent where the app runs and let the app log to its
output or files — in containers, log to stderr (Laravel: `LOG_CHANNEL=stderr`). The agent brings
the lines in. The SDK's part is the stamp: every line written while a request is being handled
ends with that request's ids,

```text
[2026-11-17 08:33:00] production.ERROR: Card declined {"order":7} [] trace_id=4bf92f3577b34da6a3ce929d0e0e4736 span_id=00f067aa0ba902b7
```

and a JSON line (Monolog's `JsonFormatter`) gets `"trace_id"` and `"span_id"` fields instead, so
Zharp shows each line with its request. Laravel's log channels (stacks included) and Symfony's
Monolog loggers are stamped with no code; for a Monolog logger of your own call
`Zharp\Monolog\LogStamping::apply($logger)`. In CLI processes (see below) what is printed during a
span is stamped too. Lines outside a request, and lines that already carry a `trace_id`, are left
as they are. `'log_correlation' => false` / `ZHARP_LOG_CORRELATION=false` turns the stamp off.

**No agent?** (Serverless, managed hosts.) Turn on `'logs' => true` / `ZHARP_LOGS=true` and the SDK
sends the logs itself. It is off by default because, with the agent also reading the output, every
line would arrive twice. Errors (exceptions and fatal errors), traces and your own `Zharp::info()`
& co calls are sent either way; with logs off, a Monolog/Laravel log call that carries an
`exception` still reports that exception (once).

With logs on, Zharp sends:

| Where | What is sent |
|---|---|
| Everywhere | Monolog (`ZharpHandler`; every channel in Symfony), Laravel's `Log` (every channel and stack; `info` and up, `ZHARP_LOG_LEVEL` to change), and in plain PHP the warnings, notices and deprecations PHP raises (`error_reporting()` decides; `@` silences; your own error handler still runs). |
| CLI only — queue workers, `artisan` and `bin/console` commands, scripts, Octane / Swoole / RoadRunner workers | What the process prints (`capture_output`, on): `echo`, `print`, `printf`, `var_dump`, warnings shown with `display_errors`, and a console command's output (`$this->info()`, `$output->writeln()`, the queue worker's job lines). |

What the process prints is read line by line: colour codes are dropped, a line is capped at 32 KB,
and its level comes from the line — a JSON record's `level`/`level_name`, Monolog's
`local.ERROR:` format, PHP's `Warning:` / `Fatal error:`, or a word such as `ERROR`, `[warn]`,
`level=error` — else `info` for stdout and `warn` for stderr. Each line is sent once: one that a
logger above already sent (Monolog writing to `php://output`, Symfony's console handler, a
displayed PHP warning) is recognised and skipped. Printed lines are sent with the next log, at
`Zharp::flush()` (after every Laravel job, response and command) or when the script ends.
`'capture_output' => false` / `ZHARP_CAPTURE_OUTPUT=false` keeps the other logs but not this.

Why the CLI only: under PHP-FPM, Apache, FrankenPHP or `php -S`, PHP's output *is* the HTTP
response — never read as logs, never stamped. (Octane buffers each request's output into its
response, so that stays out too.) Also left alone: `fwrite(STDOUT/STDERR, …)` on PHP's own
`STDOUT`/`STDERR` constants — a stream PHP is watching can no longer be handed to `proc_open` or
checked with `posix_isatty`, so tapping them could break your app — and `error_log()` calls, which
PHP writes below anything a library can see. Log those through Monolog.

## Request and response bodies

Off by default. Turn it on to see what a request sent and what came back, on its span:

```php
Zharp\Zharp::init(['key' => 'zpk_…', 'capture_bodies' => 'errors']); // requests that answered 400+; 'all' for every request
// or ZHARP_CAPTURE_BODIES=errors in .env (Laravel: config/zharp.php 'capture_bodies'), or:
'capture_bodies' => ['mode' => 'all', 'max_size' => 65536, 'redact_keys' => ['date_of_birth', '/^acct/'], 'urls' => ['#/api/#']],
```

- Laravel and Symfony request spans carry `http.request.body` and `http.response.body` (with
  their content types). Text is kept up to 32 KB (`max_size`, 128 KB at most).
- Every field name is kept. Values under keys that look secret — `password`, `pwd`, `token`,
  `secret`, `api_key`, `authorization`, `cookie`, `card`, `cvv`, `ssn` and similar — are sent as
  `[Filtered]`; under a secret object such as `card`, each value inside is filtered and its keys
  kept. Anything else (names, emails) is sent as it is: add those keys to `redact_keys` (a
  delimited regex like `'/^acct/'` is matched against keys as they are).
- File uploads list their text fields and each file as `[file photo.png · image/png · 240 KB]` —
  file contents are never read. Inline images and long base64 values become
  `[image/png · 24 KB]`; binary, file and streamed responses `[binary · application/pdf · 1.2 MB]`
  or `[event stream]`. Gzipped responses are inflated first.
- `urls` limits which requests: a host, a URL prefix, or a delimited regex tested against the URL.
- What your app reads and sends is never changed.

Whether or not bodies are on, secret-looking query values (`?token=`, `key`, `sig`, `password`, …)
are recorded as `[Filtered]` in `url.full` / `url.query` on every span.

## Database and HTTP client spans

PHP needs the [`opentelemetry` extension](https://opentelemetry.io/docs/zero-code/php/) for
automatic instrumentation. With it, add e.g. `open-telemetry/opentelemetry-auto-pdo` and
`open-telemetry/opentelemetry-auto-guzzle`: they use the providers `Zharp::init()` registers, so
their spans join the request's trace. Without the extension, Laravel and Symfony requests are still
traced by Zharp's middleware / subscriber.

## Options

| `init()` option | Environment | Default |
|---|---|---|
| `key` | `ZHARP_KEY` | — (nothing is sent without one) |
| `service` | `ZHARP_SERVICE`, `OTEL_SERVICE_NAME` | `APP_NAME` in Laravel, else `unknown-service` |
| `environment` | `ZHARP_ENVIRONMENT` | `APP_ENV` in Laravel |
| `release` | `ZHARP_RELEASE` | none |
| `sample_rate` | `ZHARP_SAMPLE_RATE` | `1.0` (share of traces kept; errors and logs always sent) |
| `logs` | `ZHARP_LOGS` | `false` — send what the app logs and prints; off, the Zharp agent brings the lines in (see [Logs](#logs)) |
| `log_correlation` | `ZHARP_LOG_CORRELATION` | `true` — lines written during a request end with `trace_id=… span_id=…` (fields in JSON lines) |
| — | `ZHARP_LOG_LEVEL` | `info` — with logs on, the lowest framework log level sent (`off` sends none) |
| `tracing` | `ZHARP_TRACING` | `true` (`false` = logs only) |
| `capture_bodies` | `ZHARP_CAPTURE_BODIES` | off (`errors`, `all` or options — see [Request and response bodies](#request-and-response-bodies)) |
| `capture_output` | `ZHARP_CAPTURE_OUTPUT` | `true` — with logs on, also what a CLI process prints (see [Logs](#logs)) |
| `capture_uncaught` | — | `true` (the framework integrations use the framework's handler instead) |

`ZHARP_DISABLED=true` turns it off. Zharp never throws into your app.

## Development

```bash
composer install
composer test
```

## License

MIT © [Zharp](https://zharp.io)
