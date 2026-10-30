<?php

declare(strict_types=1);

namespace Zharp\Tests;

use ArrayObject;
use Illuminate\Http\Request as LaravelRequest;
use Monolog\Logger;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zharp\Laravel\TraceRequests;
use Zharp\Monolog\ZharpHandler;
use Zharp\RequestSpan;
use Zharp\Symfony\RequestSubscriber;
use Zharp\Zharp;

final class ZharpTest extends TestCase
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
        $started = Zharp::init([
            'key' => 'zpk_test',
            'service' => 'php-unit',
            'environment' => 'test',
            'release' => 'api@1.0.0',
            'capture_uncaught' => false,
            'span_exporter' => new InMemorySpans($this->spans),
            'log_exporter' => new InMemoryLogs($this->logs),
        ]);
        self::assertTrue($started);
    }

    protected function tearDown(): void
    {
        Zharp::resetForTests();
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return [
            'traceparent' => '00-' . self::TRACE_ID . '-' . self::PARENT_ID . '-01',
            'baggage' => 'enduser.id=test-user,session.id=test-session',
        ];
    }

    private function serverSpan(): SpanDataInterface
    {
        foreach ($this->spans as $span) {
            if ($span->getKind() === SpanKind::KIND_SERVER) {
                return $span;
            }
        }
        self::fail('no server span');
    }

    /** @return list<ReadableLogRecord> */
    private function logs(): array
    {
        return array_values($this->logs->getArrayCopy());
    }

    /** @return list<array<string, mixed>> */
    private function errors(): array
    {
        $out = [];
        foreach ($this->logs() as $record) {
            $attributes = $record->getAttributes()->toArray();
            if (isset($attributes['exception.type'])) {
                $out[] = $attributes;
            }
        }

        return $out;
    }

    public function testResource(): void
    {
        Zharp::info('hello');
        $resource = $this->logs()[0]->getResource()->getAttributes();
        self::assertSame('php-unit', $resource->get('service.name'));
        self::assertSame('test', $resource->get('deployment.environment.name'));
        self::assertSame('api@1.0.0', $resource->get('service.version'));
        self::assertSame('zharp', $resource->get('telemetry.sdk.name'));
        self::assertSame('php', $resource->get('telemetry.sdk.language'));
        self::assertSame(Zharp::VERSION, $resource->get('telemetry.sdk.version'));
    }

    public function testRequestSpanContinuesTraceAndCarriesBaggage(): void
    {
        $span = RequestSpan::start('GET', 'https', 'shop.test', 443, '/orders/7', null, self::headers());
        self::assertNotNull($span);
        Zharp::warn('inside', ['order_id' => 7]);
        $span->setRoute('/orders/{id}');
        $span->end(200);

        $server = $this->serverSpan();
        self::assertSame(self::TRACE_ID, $server->getTraceId());
        self::assertSame(self::PARENT_ID, $server->getParentSpanId());
        self::assertSame('GET /orders/{id}', $server->getName());
        self::assertSame('/orders/{id}', $server->getAttributes()->get('http.route'));
        self::assertSame(200, $server->getAttributes()->get('http.response.status_code'));
        self::assertSame('test-user', $server->getAttributes()->get('enduser.id'));
        self::assertSame('test-session', $server->getAttributes()->get('session.id'));

        $log = $this->logs()[0];
        self::assertSame(self::TRACE_ID, $log->getSpanContext()->getTraceId());
        self::assertSame(13, $log->getSeverityNumber());
        self::assertSame('test-user', $log->getAttributes()->get('enduser.id'));
        self::assertSame('test-session', $log->getAttributes()->get('session.id'));
        self::assertSame(7, $log->getAttributes()->get('order_id'));
    }

    public function testCaptureErrorIsHandled(): void
    {
        try {
            throw new RuntimeException('card declined');
        } catch (RuntimeException $e) {
            Zharp::captureError($e, ['order_id' => 9]);
        }
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame(RuntimeException::class, $errors[0]['exception.type']);
        self::assertSame('card declined', $errors[0]['exception.message']);
        self::assertSame('true', $errors[0]['zharp.handled']);
        self::assertSame(9, $errors[0]['order_id']);
        self::assertStringStartsWith("RuntimeException: card declined\n#0 " . __FILE__, $errors[0]['exception.stacktrace']);
    }

    public function testUncaughtIsReportedOnceAndFailsTheSpan(): void
    {
        $span = RequestSpan::start('POST', 'https', 'shop.test', null, '/pay', null, self::headers());
        $error = new RuntimeException('gateway down', 0, new \LogicException('cause'));
        $span->recordException($error);
        // The framework then logs it; the log line stays, the error isn't reported again.
        $logger = new Logger('app', [new ZharpHandler()]);
        $logger->error('gateway down', ['exception' => $error]);
        $span->end(500);

        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('false', $errors[0]['zharp.handled']);
        self::assertStringContainsString('Caused by: LogicException: cause', $errors[0]['exception.stacktrace']);
        self::assertCount(2, $this->logs());
        self::assertSame('Error', $this->serverSpan()->getStatus()->getCode());
        self::assertSame('gateway down', $this->serverSpan()->getStatus()->getDescription());
    }

    public function testMonologHandler(): void
    {
        $logger = new Logger('billing', [new ZharpHandler('info')]);
        $logger->debug('not sent');
        $logger->warning('Invoice late', ['invoice' => 12]);
        $logs = $this->logs();
        self::assertCount(1, $logs);
        self::assertSame('Invoice late', $logs[0]->getBody());
        self::assertSame(13, $logs[0]->getSeverityNumber());
        self::assertSame(12, $logs[0]->getAttributes()->get('invoice'));
        self::assertSame('billing', $logs[0]->getAttributes()->get('log.channel'));
    }

    public function testSetUserOverridesBaggage(): void
    {
        $span = RequestSpan::start('GET', 'https', 'shop.test', null, '/me', null, self::headers());
        Zharp::setUser(42, 'a@example.com');
        Zharp::info('after setUser');
        $span->end(200);
        $attributes = $this->logs()[0]->getAttributes();
        self::assertSame('42', $attributes->get('enduser.id'));
        self::assertSame('a@example.com', $attributes->get('enduser.email'));
        self::assertSame('test-session', $attributes->get('session.id'));
    }

    public function testStartSpanAndTags(): void
    {
        Zharp::setTag('plan', 'pro');
        $result = Zharp::startSpan('render invoice', static function () {
            Zharp::debug('inside');

            return 42;
        });
        self::assertSame(42, $result);
        $span = $this->spans[0];
        self::assertSame('render invoice', $span->getName());
        self::assertSame('pro', $span->getAttributes()->get('plan'));
        self::assertSame($span->getTraceId(), $this->logs()[0]->getSpanContext()->getTraceId());
    }

    public function testLaravelMiddleware(): void
    {
        $request = LaravelRequest::create('/orders/7', 'GET', [], [], [], [
            'HTTP_TRACEPARENT' => self::headers()['traceparent'],
            'HTTP_BAGGAGE' => self::headers()['baggage'],
        ]);
        $response = (new TraceRequests())->handle($request, static function () {
            Zharp::info('in controller');

            return new Response('ok', 201);
        });
        self::assertSame(201, $response->getStatusCode());
        $server = $this->serverSpan();
        self::assertSame(self::TRACE_ID, $server->getTraceId());
        self::assertSame(201, $server->getAttributes()->get('http.response.status_code'));
        self::assertSame('/orders/7', $server->getAttributes()->get('url.path'));
        self::assertSame('test-session', $this->logs()[0]->getAttributes()->get('session.id'));
        self::assertNull(TraceRequests::current());
    }

    public function testSymfonySubscriber(): void
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $request = Request::create('/orders/7', 'GET', [], [], [], [
            'HTTP_TRACEPARENT' => self::headers()['traceparent'],
            'HTTP_BAGGAGE' => self::headers()['baggage'],
        ]);
        $request->attributes->set('_route', 'order_show');
        $subscriber = new RequestSubscriber();

        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom')));
        $response = new Response('error', 500);
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));

        $server = $this->serverSpan();
        self::assertSame(self::TRACE_ID, $server->getTraceId());
        self::assertSame('GET order_show', $server->getName());
        self::assertSame(500, $server->getAttributes()->get('http.response.status_code'));
        self::assertSame('Error', $server->getStatus()->getCode());
        $errors = $this->errors();
        self::assertCount(1, $errors);
        self::assertSame('false', $errors[0]['zharp.handled']);
        self::assertSame('test-user', $errors[0]['enduser.id']);
    }

    public function testNoKeyStartsNothing(): void
    {
        Zharp::resetForTests();
        self::assertFalse(Zharp::init(['quiet' => true]));
        Zharp::info('dropped');
        Zharp::captureError(new RuntimeException('dropped'));
        self::assertNull(RequestSpan::start('GET', 'http', 'x', null, '/', null, []));
    }
}
