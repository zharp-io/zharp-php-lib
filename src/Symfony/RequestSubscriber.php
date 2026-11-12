<?php

declare(strict_types=1);

namespace Zharp\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Zharp\Internal\BodySettings;
use Zharp\Internal\HttpFoundationBodies;
use Zharp\RequestSpan;
use Zharp\Zharp;

/**
 * One SERVER span per main request, from kernel.request to kernel.terminate, continuing the
 * caller's trace; kernel.exception reports what escaped a controller (unhandled). Telemetry is
 * sent at kernel.terminate, after the response is out.
 */
final class RequestSubscriber implements EventSubscriberInterface
{
    private ?RequestSpan $span = null;
    private int $status = 0;

    public static function getSubscribedEvents(): array
    {
        return [
            // Before everything, so the span covers routing, security and the controller.
            KernelEvents::REQUEST => ['onRequest', 4096],
            // Before Symfony's ErrorListener logs it, so that log line isn't a second report.
            KernelEvents::EXCEPTION => ['onException', 64],
            // After every other listener has had its say on the response.
            KernelEvents::RESPONSE => ['onResponse', -4096],
            KernelEvents::TERMINATE => ['onTerminate', -4096],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !Zharp::isActive()) {
            return;
        }
        $this->finish(); // a previous request in a long-running worker that never terminated
        Zharp::clearUser();
        $request = $event->getRequest();
        $this->status = 0;
        $this->span = RequestSpan::start(
            $request->getMethod(),
            $request->getScheme(),
            $request->getHost(),
            (int) $request->getPort() ?: null,
            $request->getPathInfo(),
            $request->getQueryString(),
            $request->headers->all(),
            $request->getClientIp(),
        );
    }

    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        // 404s, 403s and the like are answers, not failures.
        if ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500) {
            return;
        }
        if ($this->span !== null) {
            $this->span->recordException($error);
        } else {
            Zharp::reportException($error, false);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->span === null) {
            return;
        }
        $this->status = $event->getResponse()->getStatusCode();
        $route = $event->getRequest()->attributes->get('_route');
        if (is_string($route) && $route !== '') {
            $this->span->setRoute($route);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->status === 0) {
            $this->status = $event->getResponse()->getStatusCode();
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $this->finish(static fn (BodySettings $settings): array => HttpFoundationBodies::attributes($request, $response, $settings));
        Zharp::flush();
    }

    /** @param (callable(BodySettings): array<string, mixed>)|null $bodies */
    private function finish(?callable $bodies = null): void
    {
        if ($this->span !== null) {
            $this->span->end($this->status, $bodies);
            $this->span = null;
        }
        Zharp::clearUser();
    }
}
