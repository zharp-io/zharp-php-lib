# Changelog

## v0.6.0 (`zharp/zharp`) — 2026-11-24

- Plain PHP: `Zharp::init()` in a web request traces the request PHP is serving, from `$_SERVER`
  (`trace_request`, `ZHARP_TRACE_REQUEST`): named by its script, or by route with
  `Zharp::setRoute()`; it continues the caller's trace and ends with the response's status, after the
  app's own shutdown functions (and `fastcgi_finish_request()` under PHP-FPM).
- An uncaught exception with no other handler answers the request with 500 and is shown where PHP
  shows errors, as without Zharp (it was a blank 200). A fatal error fails the request too.
- `RequestSpan::fromGlobals()`, `Zharp::request()`.

## v0.5.0 (`zharp/zharp`) — 2026-11-21

- Request and response headers on the request's span (`capture_headers`, `ZHARP_CAPTURE_HEADERS`); secrets are sent as `[Filtered]`.
- Failed queries are named from the database's code (SQLSTATE) and leave out the row's values.
- The code that ran goes with each error (`capture_source_context`); stack lines name the function they are in, without arguments.
- Laravel: both options in `config/zharp.php`. Works with Laravel 13 and Guzzle 8.

## v0.4.0 (`zharp/zharp`) — 2026-11-17

**Behaviour change:** logs are no longer sent unless `logs` / `ZHARP_LOGS` is on. Errors, traces
and `Zharp::log()` calls are still sent; run the Zharp agent (lines are linked to their request
by the new stamp), or set `ZHARP_LOGS=true` where there is no agent.

- Logs are off by default: `logs` (`ZHARP_LOGS=true`) sends what the app logs and prints. Where
  the Zharp agent reads the app's output it already brings those lines in; sending them from the
  SDK too made every line arrive twice. Errors, traces and `Zharp::log()` calls are sent either
  way, as is an exception passed to a Monolog/Laravel log call.
- `log_correlation` (`ZHARP_LOG_CORRELATION`), on by default: every line written during a request
  ends with ` trace_id=<32 hex> span_id=<16 hex>` — or gets `trace_id`/`span_id` fields in a JSON
  line — so Zharp links the agent's copy to its request. Laravel's log channels (stacks included)
  and Symfony's Monolog loggers get it with no code (`Zharp\Monolog\LogStamping::apply()` for
  your own); in the CLI, what is printed during a span too. Lines outside a request, and lines
  that already carry a trace_id, are unchanged.
- With logs on, `capture_output` (`ZHARP_CAPTURE_OUTPUT`, on): logs arrive however they are written.
  In plain PHP, PHP's warnings, notices and deprecations are sent (chaining the error handler set
  before; `error_reporting()` and `@` respected). In the CLI — queue workers, artisan and
  bin/console commands, scripts, Octane/Swoole/RoadRunner workers — what the process prints
  (`echo`, `print`, `var_dump`, displayed warnings, a console command's output) is sent line by
  line with the trace it was printed in; the level is read from the line (JSON `level`, Monolog's
  `local.ERROR:`, PHP's `Warning:`, a word like `ERROR` or `[warn]`), else info for stdout and warn
  for stderr; colour codes stripped, 32 KB per line. A line a logger already sent is not sent
  again. Never under PHP-FPM/Apache/FrankenPHP/`php -S`, where the output is the HTTP response.

## v0.3.0 (`zharp/zharp`)

- `capture_bodies` (`ZHARP_CAPTURE_BODIES`), off by default: request and response bodies on the
  Laravel and Symfony request spans (`http.request.body`, `http.response.body`, their content
  types and `.truncated`) — `errors` (400 and above) or `all`. Same rules as every Zharp SDK:
  field names kept, secret values `[Filtered]` (secret objects keep their keys), uploads as fields
  plus `[file name · type · size]`, inline images and binary/streamed responses described, 32 KB
  default (128 KB max), `redact_keys` and `urls`.
- Secret-looking query values are `[Filtered]` in `url.full` / `url.query` on every span, with or
  without `capture_bodies`.

## v0.2.0 (`zharp/zharp`)

- First `zharp/zharp` release of Zharp Application Monitoring for PHP, on OpenTelemetry: `Zharp::init()` sets up
  the OTel SDK (OTLP/HTTP protobuf with gzip, resource, W3C tracecontext + baggage) and registers it
  as the OTel globals.
- Laravel: auto-discovered service provider — a SERVER span per request (global middleware),
  exceptions reported through Laravel's handler (unhandled), Laravel's logs bridged, flush after
  each response and queue job.
- Symfony: `ZharpBundle` — a SERVER span per main request (kernel events), `kernel.exception`
  reported (unhandled), every Monolog channel bridged.
- `Zharp\Monolog\ZharpHandler` for Monolog 2/3; `enduser.id` / `session.id` from baggage on every
  span and log; `Zharp::setUser`, `setTag`, `captureError` (handled), `startSpan`, `flush`.
- Logs only: `'tracing' => false` / `ZHARP_TRACING=false` sends logs, errors and context with no
  spans and no outgoing `traceparent`; an incoming trace still reaches the request's logs.
- Each exception is reported once even when both the framework handler and its log see it.
- Replaces the logs-only `zharp/logs` package, whose v0.1.0 and v0.1.1 are the earlier tags in this
  repository; `zharp/zharp` starts at v0.2.0.
