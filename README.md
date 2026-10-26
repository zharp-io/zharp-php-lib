# Zharp Logs for PHP and Laravel

Send your PHP app's logs and exceptions to [Zharp](https://zharp.io). One package for plain PHP,
Laravel and anything that uses Monolog (Symfony too). No dependencies; PHP 7.4 and up.

Full documentation: [zharp.io/docs/sdks/php](https://zharp.io/docs/sdks/php)

---

## 1. Install

```bash
composer require zharp/logs
```

## 2. Get an API key

1. In Zharp, open **Integrations & API** and choose **Main API keys**.
2. Click **Create API key**, give it a name, and tick **Ingest only**. That key can send logs but
   can't read anything, so it's safe in a server's environment.
3. Click **Create key** and copy the key — it's shown only once.

<p>
  <img src="https://www.zharp.io/images/docs/api-key-create.png" alt="Create API key, with Ingest only ticked" width="48%">
  <img src="https://www.zharp.io/images/docs/api-key-created.png" alt="The new key, shown once with a Copy button" width="48%">
</p>

## 3. Start it

### Laravel

Nothing to register — the package is discovered. Add two lines to `.env`:

```bash
ZHARP_API_KEY=your-ingest-only-key
LOG_STACK=single,zharp
```

That's all: everything Laravel logs goes to Zharp too — including every exception Laravel reports,
with its stack. The service name is your `APP_NAME` and the environment your `APP_ENV` unless you set
`ZHARP_SERVICE` and `ZHARP_ENV`.

Check it works:

```bash
php artisan zharp:test
```

```
Sent. Open Logs in Zharp and pick the service "checkout-api".
```

(On Laravel 10 and older, add `'zharp'` to the `channels` of the `stack` channel in
`config/logging.php` instead of `LOG_STACK`.)

### Plain PHP

Call `init()` once, as early as possible — at the top of your front controller or bootstrap file:

```php
use Zharp\Logs\Zharp;

Zharp::init(['api_key' => 'your-ingest-only-key', 'service' => 'checkout-api', 'environment' => 'production']);
```

Or set `ZHARP_API_KEY`, `ZHARP_SERVICE` and `ZHARP_ENV` in the environment and call `Zharp::init()`
with nothing. Without a service name, the name in your `composer.json` is used (`acme/checkout-api` →
`checkout-api`).

In a script or command it prints one line to stderr so you know it's working:

```
[Zharp] Logging active — service "checkout-api", environment "production"
```

With no API key anywhere it says how to give it one and sends nothing — it never throws.

## 4. Log

```php
Zharp::info('User signed in', ['user_id' => 42]);
Zharp::warn('Slow network detected', ['latency_ms' => 3200]);

try {
    $gateway->charge($order);
} catch (Throwable $e) {
    Zharp::error('Payment failed', ['order_id' => $order->id, 'exception' => $e]);
    // or the exception alone:
    Zharp::captureException($e);
}
```

`debug`, `fatal` and the PSR-3 names (`warning`, `notice`, `critical`…) work too. The array is
optional and is kept with the log. An exception — as the message, or under `'exception'` in the
array, where PSR-3 puts it — brings its class and full stack, previous exceptions included.

In Laravel, keep using `Log::info(...)`, `report($e)` and the rest — with `zharp` in your log stack
they reach Zharp.

## 5. See your logs

Sign in at [zharp.io](https://zharp.io/login), open **Logs**, and pick your service in the service
dropdown.

![The Logs page on zharp.io](https://www.zharp.io/images/docs/logs-dashboard.png)

---

## When logs are sent

PHP has no background threads, so logs wait in memory and are sent when the request ends — under
PHP-FPM (and LiteSpeed) **after the response has gone**, so visitors never wait on Zharp. PHP's
built-in server (`php -S`, `artisan serve`) can't do that, so there a request waits for the send.

In a long-running process — a queue worker, a daemon, a script that loops — logs also go every 5
seconds, and `error` and `fatal` at once. In Laravel, what's queued is sent after each queued job
and each Octane request too.

`Zharp::flush()` sends what's queued now; `Zharp::close()` sends it and stops.

## Uncaught exceptions and fatal errors

In plain PHP, from the moment `init()` succeeds, an uncaught exception is sent as a `fatal` log with
its stack, then handled as before — by the handler you had set, or as PHP does (reported, exit code
255). A fatal error (out of memory, a fatal error in an include) is sent too. Turn both off with
`'capture_exceptions' => false`.

In Laravel, Laravel's own handler deals with them and reports them through the log, so they arrive
through the `zharp` channel.

## Monolog and Symfony

`Zharp\Logs\Monolog\ZharpHandler` works with Monolog 2 and 3:

```php
use Zharp\Logs\Monolog\ZharpHandler;

Zharp::init();
$logger->pushHandler(new ZharpHandler());
```

In Symfony, register it as a service and add it as a `service` handler in `monolog.yaml`. Each record
goes with its channel, context and extra, and an exception under `'exception'` in the context brings
its stack.

## Options

| `init()` option | Environment variable | Default |
|---|---|---|
| `api_key` | `ZHARP_API_KEY` | — |
| `service` | `ZHARP_SERVICE`, then `OTEL_SERVICE_NAME` | the name in your `composer.json` (Laravel: `APP_NAME`), else `unknown-service` |
| `environment` | `ZHARP_ENV` | none (Laravel: `APP_ENV`) |
| `app_version` | `ZHARP_VERSION` | none |
| `source` | `ZHARP_SOURCE` | none |
| `endpoint` | `ZHARP_ENDPOINT` | `https://api.zharp.io/api/v1` |
| `capture_exceptions` | — | on (off in Laravel, which handles them itself) |
| `flush_interval` | — | 5 seconds, for long-running processes |
| `max_batch_size` | — | 50 |
| `quiet` | — | off — on prints nothing at startup |

`ZHARP_DISABLED=true` turns the SDK off. In Laravel, `ZHARP_LEVEL` sets the lowest level the `zharp`
channel sends, and `php artisan vendor:publish --tag=zharp-config` gives you `config/zharp.php`.

## What's sent automatically

Every log carries a session ID — the same for one request or one script run — and where it ran:
hostname, process ID, PHP version, OS family and SAPI, and for a web request its method and path
(never the query string, which can carry tokens). Add your own fields to every log with
`Zharp::setContext(['user_id' => $user->id])`.

## Testing

```bash
php tests/run.php
```

No test framework needed: a fake Zharp runs on PHP's built-in server. `composer install` first to
include the Monolog handler test.

## Releasing (maintainers)

Packagist publishes from the GitHub tags of
[github.com/zharp-io/zharp-php-lib](https://github.com/zharp-io/zharp-php-lib), and updates by
itself through a webhook on the repository.

1. Update `VERSION` in `src/Zharp.php` and `CHANGELOG.md`; run `php tests/run.php`.
2. Commit, then tag and push: `git tag v0.1.1 && git push origin main v0.1.1`.
3. It's on [packagist.org/packages/zharp/logs](https://packagist.org/packages/zharp/logs) within a
   minute.

The webhook (already set up; for reference if it's ever lost): in the repository's **Settings →
Webhooks → Add webhook**, Payload URL `https://packagist.org/api/github?username=<packagist user>`,
content type `application/json`, the Packagist API token (Profile → Show API Token) as the secret,
and *Just the push event*. Without it, a new tag only appears after **Update** on the package page.

## License

MIT © [Zharp](https://zharp.io)
