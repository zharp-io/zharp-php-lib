# Changelog

## v0.1.0 (`zharp/zharp`)

- First release of Zharp Application Monitoring for PHP, on OpenTelemetry: `Zharp::init()` sets up
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
- Replaces the logs-only `zharp/logs` 0.1.x package.
