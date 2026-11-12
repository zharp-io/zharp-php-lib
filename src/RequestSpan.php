<?php

declare(strict_types=1);

namespace Zharp;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;
use Zharp\Internal\Bodies;
use Zharp\Internal\BodySettings;

/**
 * The SERVER span of one incoming request, for framework integrations: continues the caller's
 * trace from traceparent, carries baggage (enduser.id, session.id) into the request's context,
 * and is active until end(). With tracing off there is no span: only the caller's trace context
 * is made current, so logs carry its trace_id.
 *
 * Without the opentelemetry extension nothing traces requests automatically, so the Laravel
 * middleware and the Symfony subscriber make this span themselves.
 */
final class RequestSpan
{
    private bool $ended = false;
    private bool $failed = false;

    private function __construct(
        private readonly SpanInterface $span,
        private readonly ScopeInterface $scope,
        private readonly string $method,
        private readonly string $url = '',
    ) {
    }

    /**
     * @param array<string, string|list<string>> $headers request headers (any case)
     */
    public static function start(
        string $method,
        string $scheme,
        string $host,
        ?int $port,
        string $path,
        ?string $query,
        array $headers,
        ?string $clientAddress = null,
    ): ?self {
        if (!Zharp::isActive()) {
            return null;
        }
        $carrier = array_change_key_case($headers, CASE_LOWER);
        $parent = Zharp::propagator()->extract($carrier);
        $userAgent = $carrier['user-agent'] ?? null;
        $userAgent = is_array($userAgent) ? ($userAgent[0] ?? null) : $userAgent;

        if (!Zharp::isTracing()) {
            // Logs only: no span. The caller's trace (and baggage) is still made current, so log
            // lines and errors in this request carry its trace_id; setRoute/end do nothing.
            return new self(Span::fromContext($parent), $parent->activate(), $method);
        }

        $span = Zharp::tracer()->spanBuilder($method)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parent)
            ->setAttributes(array_filter([
                'http.request.method' => $method,
                'url.scheme' => $scheme,
                'url.path' => $path,
                'url.query' => $query !== '' ? $query : null,
                'server.address' => $host,
                'server.port' => $port,
                'user_agent.original' => is_string($userAgent) ? $userAgent : null,
                'client.address' => $clientAddress,
            ], static fn ($v) => $v !== null && $v !== ''))
            ->startSpan();
        // The extracted context holds the baggage: logs during the request read it from here.
        $scope = $span->storeInContext($parent)->activate();
        $url = $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path . ($query !== null && $query !== '' ? '?' . $query : '');

        return new self($span, $scope, $method, $url);
    }

    public function span(): SpanInterface
    {
        return $this->span;
    }

    /** The route template ("/orders/{id}") or name, once routing has happened. */
    public function setRoute(?string $route): void
    {
        if ($route === null || $route === '' || $this->ended) {
            return;
        }
        $this->span->setAttribute('http.route', $route);
        $this->span->updateName($this->method . ' ' . $route);
    }

    /** An exception that escaped the app: reported unhandled; the span fails. */
    public function recordException(Throwable $e): void
    {
        if (!$this->ended) {
            $this->failed = true;
            Zharp::reportException($e, false, $this->span);
        }
    }

    /**
     * Ends the span. $bodies, when capture_bodies is on, gives the request and response bodies'
     * attributes; it is called only when this request's bodies are wanted (by default: status 400
     * and above) and its URL is allowed.
     *
     * @param (callable(BodySettings): array<string, mixed>)|null $bodies
     */
    public function end(int $statusCode, ?callable $bodies = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        if ($statusCode > 0) {
            $this->span->setAttribute('http.response.status_code', $statusCode);
        }
        $settings = Zharp::bodySettings();
        if ($bodies !== null && $settings !== null && Bodies::wanted($settings, $statusCode) && $settings->allows($this->url)) {
            try {
                $this->span->setAttributes($bodies($settings));
            } catch (Throwable) {
                // Never let telemetry break a request.
            }
        }
        if ($statusCode >= 500 && !$this->failed) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }
        try {
            $this->scope->detach();
        } catch (Throwable) {
        }
        $this->span->end();
    }
}
