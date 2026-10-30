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
# ZHARP_ENDPOINT=…   optional
```

Every HTTP request gets a SERVER span named by its route (`GET /orders/{id}`), continuing the
caller's trace. Exceptions Laravel reports become unhandled Zharp errors with the request's trace;
everything Laravel logs (`info` and up, `ZHARP_LOG_LEVEL` to change) is sent with the trace, user
and session. Telemetry goes once the response is out, and after every queue job.
`php artisan vendor:publish --tag=zharp-config` publishes `config/zharp.php`.

## Symfony

Same environment variables, then enable the bundle in `config/bundles.php`:

```php
Zharp\Symfony\ZharpBundle::class => ['all' => true],
```

Every main request gets a SERVER span (named by the route name), an exception that escapes a
controller is an unhandled error (4xx `HttpException`s aren't), and with MonologBundle every
channel's logs are sent.

## Plain PHP

```php
Zharp\Zharp::init(['key' => 'zpk_…', 'service' => 'checkout-api', 'environment' => 'production']);
```

An uncaught exception or fatal error is reported; `Zharp\RequestSpan::start(...)` makes a request
span for frameworks without an integration. Monolog: `$logger->pushHandler(new Zharp\Monolog\ZharpHandler('info'))`.

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

## Logs only

Logs, errors, user/session and tags without any tracing:

```php
Zharp\Zharp::init(['key' => 'zpk_…', 'service' => 'checkout-api', 'tracing' => false]);
```

or `ZHARP_TRACING=false` in `.env` (Laravel, Symfony, plain PHP). No spans are made or sent,
`Zharp::startSpan()` just runs its callback, and OpenTelemetry instrumentation gets no tracer or
propagator, so nothing adds `traceparent`/`baggage` to outgoing requests. An incoming
`traceparent` and `baggage` are still read by the Laravel middleware / Symfony subscriber, so a
request's logs and errors keep the caller's trace id, user and session.

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
| `endpoint` | `ZHARP_ENDPOINT` | `https://api.zharp.io/api/v1/otlp` |
| `sample_rate` | `ZHARP_SAMPLE_RATE` | `1.0` (share of traces kept; logs and errors always sent) |
| — | `ZHARP_LOG_LEVEL` | `info` (`off` sends no framework logs) |
| `tracing` | `ZHARP_TRACING` | `true` (`false` = logs only) |
| `capture_uncaught` | — | `true` (the framework integrations use the framework's handler instead) |

`ZHARP_DISABLED=true` turns it off. Zharp never throws into your app.

## Development

```bash
composer install
composer test
```

## License

MIT © [Zharp](https://zharp.io)
