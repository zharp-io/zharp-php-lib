<?php

declare(strict_types=1);

namespace Zharp\Tests;

use ArrayObject;
use Illuminate\Http\Request as LaravelRequest;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Zharp\Laravel\TraceRequests;
use Zharp\RequestSpan;
use Zharp\Zharp;

/** tracing => false: logs, errors and context go out; no spans, no traceparent. */
final class LogsOnlyTest extends TestCase
{
    private const TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const PARENT_ID = '00f067aa0ba902b7';

    private ArrayObject $spans;
    private ArrayObject $logs;

    protected function setUp(): void
    {
        Zharp::resetForTests();
        $this->spans = new ArrayObject();
        $this->logs = new ArrayObject();
    }

    protected function tearDown(): void
    {
        Zharp::resetForTests();
        unset($_SERVER['ZHARP_TRACING']);
    }

    /** @param array<string, mixed> $options */
    private function start(array $options = ['tracing' => false]): void
    {
        self::assertTrue(Zharp::init($options + [
            'key' => 'zpk_test',
            'service' => 'php-unit',
            'capture_uncaught' => false,
            'span_exporter' => new InMemorySpans($this->spans),
            'log_exporter' => new InMemoryLogs($this->logs),
        ]));
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01',
            'baggage' => 'enduser.id=test-user,session.id=test-session',
        ];
    }

    /** @return list<ReadableLogRecord> */
    private function logs(): array
    {
        return array_values($this->logs->getArrayCopy());
    }

    public function testStartSpanRunsTheWorkButMakesNoSpan(): void
    {
        $this->start();
        self::assertFalse(Zharp::isTracing());
        $carrier = [];
        $result = Zharp::startSpan('render invoice', static function () use (&$carrier) {
            Zharp::info('inside');
            // Nothing to propagate: an outgoing request would carry no traceparent.
            TraceContextPropagator::getInstance()->inject($carrier);

            return 42;
        });
        self::assertSame(42, $result);
        self::assertCount(0, $this->spans);
        self::assertSame([], $carrier);
        self::assertCount(1, $this->logs());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testIncomingTraceStillReachesLogsAndErrors(): void
    {
        $this->start();
        $span = RequestSpan::start('GET', 'https', 'shop.test', 443, '/orders/7', null, self::headers());
        self::assertNotNull($span);
        Zharp::setTag('plan', 'pro');
        Zharp::warn('inside', ['order_id' => 7]);
        Zharp::captureError(new RuntimeException('card declined'));
        $span->recordException(new RuntimeException('gateway down'));
        $span->setRoute('/orders/{id}');
        $span->end(500);

        self::assertCount(0, $this->spans);
        $logs = $this->logs();
        self::assertCount(3, $logs);
        foreach ($logs as $log) {
            self::assertSame(self::TRACE_ID, $log->getSpanContext()->getTraceId());
            self::assertSame('test-user', $log->getAttributes()->get('enduser.id'));
            self::assertSame('test-session', $log->getAttributes()->get('session.id'));
            self::assertSame('pro', $log->getAttributes()->get('plan'));
        }
        self::assertSame(7, $logs[0]->getAttributes()->get('order_id'));
        self::assertSame(RuntimeException::class, $logs[1]->getAttributes()->get('exception.type'));
        self::assertSame('true', $logs[1]->getAttributes()->get('zharp.handled'));
        self::assertSame('gateway down', $logs[2]->getAttributes()->get('exception.message'));
        self::assertSame('false', $logs[2]->getAttributes()->get('zharp.handled'));
        // The request's context is gone once it ends.
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testLaravelMiddlewareWithTracingOff(): void
    {
        $this->start();
        $request = LaravelRequest::create('/orders/7', 'GET', [], [], [], [
            'HTTP_TRACEPARENT' => self::headers()['traceparent'],
            'HTTP_BAGGAGE' => self::headers()['baggage'],
        ]);
        $response = (new TraceRequests())->handle($request, static function () {
            Zharp::info('in controller');

            return new Response('ok', 201);
        });
        self::assertSame(201, $response->getStatusCode());
        self::assertCount(0, $this->spans);
        self::assertSame(self::TRACE_ID, $this->logs()[0]->getSpanContext()->getTraceId());
        self::assertSame('test-session', $this->logs()[0]->getAttributes()->get('session.id'));
    }

    public function testEnvironmentVariable(): void
    {
        $_SERVER['ZHARP_TRACING'] = 'false';
        $this->start([]);
        self::assertFalse(Zharp::isTracing());
        Zharp::startSpan('work', static fn () => null);
        self::assertCount(0, $this->spans);
    }

    public function testTracingIsOnByDefault(): void
    {
        $this->start([]);
        self::assertTrue(Zharp::isTracing());
        Zharp::startSpan('work', static fn () => null);
        self::assertCount(1, $this->spans);
    }
}
