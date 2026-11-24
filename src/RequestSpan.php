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
use Zharp\Internal\Headers;

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
        if (Zharp::headersEnabled()) {
            $span->setAttributes(Headers::attributes('request', $headers, Zharp::bodySettings()));
        }
        // The extracted context holds the baggage: logs during the request read it from here.
        $scope = $span->storeInContext($parent)->activate();
        $url = $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path . ($query !== null && $query !== '' ? '?' . $query : '');

        return new self($span, $scope, $method, $url);
    }

    /**
     * The request PHP is serving, read from $_SERVER (or $server): for plain PHP under PHP-FPM,
     * Apache, FrankenPHP or `php -S`. Named "METHOD /script.php" until setRoute() names it by
     * route, so a site of .php pages gets a name per page with no code. Null in the CLI (there is
     * no request) and before init.
     *
     * @param array<string, mixed>|null $server
     */
    public static function fromGlobals(?array $server = null): ?self
    {
        $server ??= $_SERVER;
        $method = $server['REQUEST_METHOD'] ?? null;
        if (!is_string($method) || $method === '') {
            return null;
        }
        $https = $server['HTTPS'] ?? '';
        $scheme = (is_string($https) && $https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
        $forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($forwarded)) {
            $proto = strtolower(trim(explode(',', $forwarded)[0]));
            if ($proto === 'http' || $proto === 'https') {
                $scheme = $proto;
            }
        }
        $hostHeader = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        $host = $hostHeader;
        $port = null;
        if (preg_match('/^(\[[^\]]+\]|[^:]+)(?::(\d+))?$/', $hostHeader, $m) === 1) {
            $host = $m[1];
            $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;
        }
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $query = $server['QUERY_STRING'] ?? parse_url($uri, PHP_URL_QUERY);
        $query = is_string($query) ? $query : null;

        $headers = [];
        foreach ($server as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
            } elseif (($name === 'CONTENT_TYPE' || $name === 'CONTENT_LENGTH') && $value !== '') {
                $headers[strtolower(str_replace('_', '-', $name))] = $value;
            }
        }
        $client = $server['REMOTE_ADDR'] ?? null;

        $request = self::start($method, $scheme, $host, $port, $path, $query, $headers, is_string($client) && $client !== '' ? $client : null);
        $script = $server['SCRIPT_NAME'] ?? null;
        if ($request !== null && $request->url !== '' && is_string($script) && str_ends_with($script, '.php')) {
            $request->span->updateName($method . ' ' . $script);
        }

        return $request;
    }

    /** Whether end() has run. */
    public function hasEnded(): bool
    {
        return $this->ended;
    }

    /** A fatal error ended the request (one with no exception: out of memory, time limit). */
    public function fail(string $message = ''): void
    {
        if (!$this->ended) {
            $this->failed = true;
            if ($this->span->isRecording()) {
                $this->span->setStatus(StatusCode::STATUS_ERROR, $message);
            }
        }
    }

    /** Whether something that escaped the app was recorded on it. */
    public function hasFailed(): bool
    {
        return $this->failed;
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
     * @param array<string, string|list<string|null>|null> $responseHeaders
     */
    public function end(int $statusCode, ?callable $bodies = null, array $responseHeaders = []): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        if ($responseHeaders !== [] && Zharp::headersEnabled()) {
            $this->span->setAttributes(Headers::attributes('response', $responseHeaders, Zharp::bodySettings()));
        }
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
