<?php

declare(strict_types=1);

namespace Zharp\Tests;

use ArrayObject;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use PHPUnit\Framework\TestCase;
use Zharp\RequestSpan;
use Zharp\Zharp;

/** Plain PHP, no framework: the request PHP is serving, traced from $_SERVER. */
final class PlainPhpTest extends TestCase
{
    private ArrayObject $spans;
    private ArrayObject $logs;
    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        Zharp::resetForTests();
        $this->spans = new ArrayObject();
        $this->logs = new ArrayObject();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        Zharp::resetForTests();
        $_SERVER = $this->server;
    }

    /** @param array<string, mixed> $options */
    private function init(array $options = []): void
    {
        self::assertTrue(Zharp::init($options + [
            'key' => 'zpk_test',
            'service' => 'plain-php',
            'span_exporter' => new InMemorySpans($this->spans),
            'log_exporter' => new InMemoryLogs($this->logs),
        ]));
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

    public function testRequestFromServerVariables(): void
    {
        $this->init(['capture_uncaught' => false]);
        $request = RequestSpan::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/orders.php?id=7&token=secret',
            'QUERY_STRING' => 'id=7&token=secret',
            'SCRIPT_NAME' => '/orders.php',
            'HTTPS' => 'on',
            'HTTP_HOST' => 'shop.test:8443',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            'HTTP_BAGGAGE' => 'enduser.id=u-1,session.id=s-1',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '198.51.100.4',
        ]);
        self::assertNotNull($request);
        $request->end(200);

        $span = $this->serverSpan();
        $attributes = $span->getAttributes();
        self::assertSame('GET /orders.php', $span->getName());
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span->getTraceId());
        self::assertSame('00f067aa0ba902b7', $span->getParentSpanId());
        self::assertSame('GET', $attributes->get('http.request.method'));
        self::assertSame('https', $attributes->get('url.scheme'));
        self::assertSame('shop.test', $attributes->get('server.address'));
        self::assertSame(8443, $attributes->get('server.port'));
        self::assertSame('/orders.php', $attributes->get('url.path'));
        self::assertSame('id=7&token=[Filtered]', $attributes->get('url.query'));
        self::assertSame('198.51.100.4', $attributes->get('client.address'));
        self::assertSame('Mozilla/5.0', $attributes->get('user_agent.original'));
        self::assertSame('u-1', $attributes->get('enduser.id'));
        self::assertSame('application/json', $attributes->get('http.request.header.content-type'));
        self::assertSame(200, $attributes->get('http.response.status_code'));
    }

    public function testBehindAProxyAndOnTheDefaultPort(): void
    {
        $this->init(['capture_uncaught' => false]);
        RequestSpan::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'shop.test',
            'HTTP_X_FORWARDED_PROTO' => 'https, http',
        ])?->end(200);

        $attributes = $this->serverSpan()->getAttributes();
        self::assertSame('https', $attributes->get('url.scheme'));
        self::assertSame('shop.test', $attributes->get('server.address'));
        self::assertNull($attributes->get('server.port'));
        self::assertNull($attributes->get('url.query'));
    }

    public function testNoRequestInTheCli(): void
    {
        self::assertNull(RequestSpan::fromGlobals(['argv' => ['script.php']]));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // A CLI script (the default here) isn't serving a request, even with $_SERVER set.
        $this->init();
        self::assertNull(Zharp::request());
    }

    public function testInitTracesTheRequestAndSetRouteNamesIt(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/orders/7';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_HOST'] = 'shop.test';
        $this->init(['trace_request' => true]);

        $request = Zharp::request();
        self::assertNotNull($request);
        Zharp::info('loading order');
        Zharp::setRoute('/orders/{id}');
        $request->end(200);
        self::assertNull(Zharp::request());

        $span = $this->serverSpan();
        self::assertSame('GET /orders/{id}', $span->getName());
        self::assertSame('/orders/{id}', $span->getAttributes()->get('http.route'));
        $log = array_values($this->logs->getArrayCopy())[0];
        self::assertSame($span->getTraceId(), $log->getSpanContext()->getTraceId());
        self::assertSame($span->getSpanId(), $log->getSpanContext()->getSpanId());
    }

    public function testFrameworkIntegrationsTraceTheirOwnRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // Laravel and Symfony start Zharp with capture_uncaught off and make the span themselves.
        $this->init(['capture_uncaught' => false, 'trace_request' => true]);
        self::assertNull(Zharp::request());
    }

    /** @return array{int, list<array<string, mixed>>} exit code, what was exported */
    private function runPage(string $mode): array
    {
        $file = tempnam(sys_get_temp_dir(), 'zharp-plain');
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __DIR__ . '/fixtures/plain-request.php', $file, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        @unlink($file);

        return [$code, array_map(static fn (string $line): array => json_decode($line, true), $lines)];
    }

    /** @param list<array<string, mixed>> $exported */
    private static function exportedServerSpan(array $exported): array
    {
        foreach ($exported as $item) {
            if (($item['kind'] ?? null) === SpanKind::KIND_SERVER) {
                return $item;
            }
        }
        self::fail('no server span exported: ' . json_encode($exported));
    }

    public function testAPageEndsItsRequestWithItsStatus(): void
    {
        [$code, $exported] = $this->runPage('route');
        self::assertSame(0, $code);
        $span = self::exportedServerSpan($exported);
        self::assertSame('POST /checkout/{step}', $span['span']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $span['trace_id']);
        self::assertSame(200, $span['attributes']['http.response.status_code']);
        self::assertSame('coupon=SPRING&token=[Filtered]', $span['attributes']['url.query']);
        self::assertSame('203.0.113.9', $span['attributes']['client.address']);
        self::assertSame('u-42', $span['attributes']['enduser.id']);
        // The app's own shutdown function ran inside the request.
        $log = array_values(array_filter($exported, static fn (array $item): bool => ($item['log'] ?? null) === 'shutdown work'));
        self::assertCount(1, $log);
        self::assertSame($span['trace_id'], $log[0]['trace_id']);

        [, $exported] = $this->runPage('not-found');
        $span = self::exportedServerSpan($exported);
        self::assertSame('POST /checkout.php', $span['span']);
        self::assertSame(404, $span['attributes']['http.response.status_code']);
        self::assertSame('Unset', $span['status']);
    }

    public function testAnUncaughtExceptionIsReportedOnceAndFailsTheRequest(): void
    {
        [$code, $exported] = $this->runPage('throw');
        self::assertSame(255, $code);
        $span = self::exportedServerSpan($exported);
        self::assertSame(500, $span['attributes']['http.response.status_code']);
        self::assertSame('Error', $span['status']);

        $errors = array_values(array_filter($exported, static fn (array $item): bool => isset($item['attributes']['exception.type'])));
        self::assertCount(1, $errors);
        $error = $errors[0]['attributes'];
        self::assertSame($span['trace_id'], $errors[0]['trace_id']);
        self::assertSame('RuntimeException', $error['exception.type']);
        self::assertSame('Card declined', $error['exception.message']);
        self::assertSame('false', $error['zharp.handled']);
        self::assertSame('u-42', $error['enduser.id']);
        self::assertMatchesRegularExpression('~^RuntimeException: Card declined\n#0 .*plain-request\.php\(\d+\): charge\(\)~', $error['exception.stacktrace']);
        $code = json_decode($error['zharp.source_context'], true);
        self::assertSame("        throw new RuntimeException('Card declined');", $code[0]['code']);
    }
}
