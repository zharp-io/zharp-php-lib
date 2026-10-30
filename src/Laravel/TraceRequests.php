<?php

declare(strict_types=1);

namespace Zharp\Laravel;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Zharp\RequestSpan;
use Zharp\Zharp;

/**
 * Global middleware (added by ZharpServiceProvider): one SERVER span per request, continuing the
 * caller's trace, named by the route ("GET /orders/{id}").
 */
final class TraceRequests
{
    private static ?RequestSpan $current = null;

    /** The span of the request being handled, if any. */
    public static function current(): ?RequestSpan
    {
        return self::$current;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        if (!Zharp::isActive() || self::$current !== null) {
            return $next($request);
        }
        Zharp::clearUser(); // a long-running worker (Octane) serves many requests
        $span = RequestSpan::start(
            $request->getMethod(),
            $request->getScheme(),
            $request->getHost(),
            (int) $request->getPort() ?: null,
            $request->getPathInfo(),
            $request->getQueryString(),
            $request->headers->all(),
            $request->ip(),
        );
        self::$current = $span;

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Laravel turns exceptions into responses inside the pipeline; this is the rare escape.
            $span?->recordException($e);
            $this->finish($request, 500);
            throw $e;
        }
        $this->finish($request, $response instanceof Response ? $response->getStatusCode() : 200);

        return $response;
    }

    private function finish(Request $request, int $status): void
    {
        $span = self::$current;
        self::$current = null;
        if ($span === null) {
            return;
        }
        try {
            $route = $request->route();
            if ($route instanceof Route) {
                $span->setRoute('/' . ltrim($route->uri(), '/'));
            }
        } catch (Throwable) {
        }
        $span->end($status);
        Zharp::clearUser();
    }
}
