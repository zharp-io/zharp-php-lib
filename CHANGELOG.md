# Changelog

## Unreleased

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
