<?php

declare(strict_types=1);

namespace Zharp\Tests;

use ArrayObject;
use Illuminate\Http\Request as LaravelRequest;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogs;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpans;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Zharp\Internal\Bodies;
use Zharp\Laravel\TraceRequests;
use Zharp\Symfony\RequestSubscriber;
use Zharp\Zharp;

/**
 * capture_bodies: the shared rules (checked against the JavaScript SDK's output, byte for byte)
 * and what the Laravel middleware and the Symfony subscriber put on the request span.
 */
final class BodiesTest extends TestCase
{
    private ArrayObject $spans;

    protected function setUp(): void
    {
        Zharp::resetForTests();
    }

    protected function tearDown(): void
    {
        Zharp::resetForTests();
        unset($_SERVER['ZHARP_CAPTURE_BODIES']);
    }

    /** @param array<string, mixed> $options */
    private function start(array $options = []): void
    {
        Zharp::resetForTests();
        $this->spans = new ArrayObject();
        self::assertTrue(Zharp::init($options + [
            'key' => 'zpk_test',
            'service' => 'php-unit',
            'capture_uncaught' => false,
            'span_exporter' => new InMemorySpans($this->spans),
            'log_exporter' => new InMemoryLogs(new ArrayObject()),
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

    private function attr(string $key): mixed
    {
        return $this->serverSpan()->getAttributes()->get($key);
    }

    /** @param callable(Request): Response $controller */
    private function laravel(LaravelRequest $request, callable $controller): Response
    {
        return (new TraceRequests())->handle($request, $controller);
    }

    // ── the shared rules ──────────────────────────────────────────────────────

    public function testSameOutputAsTheJavaScriptSdk(): void
    {
        $js = json_decode((string) file_get_contents(__DIR__ . '/fixtures/bodies-js.json'), true);
        $s = Bodies::settings(['mode' => 'all', 'max_size' => 1000, 'redact_keys' => ['dateOfBirth', '/^acct/']]);
        self::assertNotNull($s);
        $pixel = 'data:image/png;base64,' . str_repeat('iVBORw0KGgo', 4000);
        $json = Bodies::json((object) [
            'email' => 'a@b.test', 'password' => 'hunter2', 'dateOfBirth' => '1990', 'acctNo' => 7,
            'card' => (object) ['brand' => 'visa', 'last4' => '4242', 'nested' => [1, (object) ['x' => 'y']]],
            'empty' => '', 'none' => null, 'list' => [(object) ['token' => 't']], 'avatar' => $pixel,
            'blob' => str_repeat('QUJD', 500) . '1', 'price' => 1.5, 'n' => 10, 'ok' => true,
            'unicode' => 'Accra ✓ é', 'slash' => 'a/b',
        ]);

        self::assertSame($js['json'], Bodies::formatBody($json, 'application/json', $s));
        self::assertSame($js['cutJson'], Bodies::formatBody('{"name":"Ada","password":"hunter2","avatar":"data:image/png;base64,' . str_repeat('A1b2', 300), 'application/json', $s, true));
        self::assertSame($js['form'], Bodies::formatBody('user=ada&password=p4ss&pwd=x&note=' . rawurlencode('data:image/png;base64,' . str_repeat('Zm9v1', 100)) . '&flag', 'application/x-www-form-urlencoded', $s));
        self::assertSame($js['loose'], Bodies::formatBody('not json "secret": "abc", "name": "x", "pin": 1234', 'text/plain', $s));
        self::assertSame($js['big'], Bodies::formatBody(str_repeat('x', 1500), 'text/plain', $s));
        self::assertSame($js['emptyObj'], Bodies::formatBody('{"a":{},"b":[],"c":{"token":{}}}', 'application/json', $s));
        self::assertSame($js['attrs'], Bodies::bodyAttributes('request', '{"a":1}', 'application/json; charset=utf-8', $s));
        self::assertSame([], Bodies::bodyAttributes('response', 'x', 'image/png', $s));
        self::assertSame($js['described'], Bodies::describedAttributes('response', 'image/png', 3000));
        self::assertSame($js['stream'], Bodies::describeBody('text/event-stream'));
        self::assertSame($js['file'], Bodies::fileLabel('me.png', 'image/png', 102400));
        self::assertSame($js['file2'], Bodies::fileLabel(null, null, 1536, true));
        self::assertSame($js['sizes'], array_map([Bodies::class, 'formatSize'], [0, 1023, 1024, 1535, 1536, 1048575, 1048576, 1572864, 10 * 1048576 + 52429]));
        self::assertSame($js['url1'], Bodies::redactUrl('https://user:pw@api.x.test/p?token=abc&page=2&key=k&state=s&session=z&sessions=3&code=&a#frag?token=x'));
        self::assertSame($js['url2'], Bodies::redactQuery('pwd=1&PWD=2&pwdx=3&api_key=4&sig=5'));
        foreach ($js['keys'] as [$key, $secret]) {
            self::assertSame($secret, Bodies::isSecretKey($key, $s), $key);
        }
    }

    public function testSettings(): void
    {
        self::assertNull(Bodies::settings(null));
        self::assertNull(Bodies::settings(false));
        self::assertNull(Bodies::settings(null, 'off'));
        self::assertNull(Bodies::settings('nope'));
        self::assertSame('errors', Bodies::settings(true)?->mode);
        self::assertSame('errors', Bodies::settings(null, 'errors')?->mode);
        self::assertSame('all', Bodies::settings(null, 'all')?->mode);
        self::assertSame('all', Bodies::settings('all')?->mode);
        self::assertSame(32768, Bodies::settings('errors')?->maxSize);
        self::assertSame(131072, Bodies::settings(['max_size' => 999999])?->maxSize);
        self::assertNull(Bodies::settings(false, 'all'), 'an explicit option wins over the environment');
        $s = Bodies::settings(['urls' => ['api.example.com', 'https://x.test/v2', '#/internal/#']]);
        self::assertTrue($s?->allows('https://api.example.com:8443/a'));
        self::assertTrue($s?->allows('https://x.test/v2/orders'));
        self::assertTrue($s?->allows('http://other.test/internal/x'));
        self::assertFalse($s?->allows('https://x.test/v1'));
    }

    // ── Laravel ───────────────────────────────────────────────────────────────

    public function testOffByDefault(): void
    {
        $this->start();
        $request = LaravelRequest::create('/signup', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"email":"a@b.test"}');
        $this->laravel($request, static fn () => new JsonResponse(['error' => 'taken'], 422));
        self::assertNull($this->attr('http.request.body'));
        self::assertNull($this->attr('http.response.body'));
    }

    public function testLaravelFailedRequestCarriesBothBodiesRedacted(): void
    {
        $this->start(['capture_bodies' => 'errors']);
        $body = '{"email":"ada@x.test","password":"pw-secret","card":{"brand":"visa","last4":"4242"}}';
        $request = LaravelRequest::create('/signup', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        $response = $this->laravel($request, static fn () => new JsonResponse(['error' => 'email taken', 'sessionToken' => 'srv-token'], 422));

        self::assertSame('{"error":"email taken","sessionToken":"srv-token"}', $response->getContent(), 'the app sends what it wrote');
        self::assertSame('{"email":"ada@x.test","password":"[Filtered]","card":{"brand":"[Filtered]","last4":"[Filtered]"}}', $this->attr('http.request.body'));
        self::assertSame('application/json', $this->attr('http.request.header.content-type'));
        self::assertSame('{"error":"email taken","sessionToken":"[Filtered]"}', $this->attr('http.response.body'));
        self::assertSame('application/json', $this->attr('http.response.header.content-type'));
    }

    public function testErrorsModeLeavesSuccessesAlone(): void
    {
        $this->start(['capture_bodies' => 'errors']);
        $request = LaravelRequest::create('/cart', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"sku":"A"}');
        $this->laravel($request, static fn () => new JsonResponse(['ok' => true], 200));
        self::assertNull($this->attr('http.request.body'));
        self::assertNull($this->attr('http.response.body'));
    }

    public function testAllModeAndTheCap(): void
    {
        $this->start(['capture_bodies' => ['mode' => 'all', 'max_size' => 100]]);
        $request = LaravelRequest::create('/notes', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], str_repeat('n', 500));
        $this->laravel($request, static fn () => new Response('saved', 201, ['Content-Type' => 'text/plain']));
        self::assertSame(str_repeat('n', 100), $this->attr('http.request.body'));
        self::assertTrue($this->attr('http.request.body.truncated'));
        self::assertSame('saved', $this->attr('http.response.body'));
    }

    public function testEnvironmentVariable(): void
    {
        $_SERVER['ZHARP_CAPTURE_BODIES'] = 'all';
        $this->start();
        $request = LaravelRequest::create('/cart', 'GET');
        $this->laravel($request, static fn () => new JsonResponse(['items' => 2]));
        self::assertSame('{"items":2}', $this->attr('http.response.body'));
    }

    public function testFormUploadKeepsFieldsAndDescribesFiles(): void
    {
        $this->start(['capture_bodies' => 'errors']);
        $path = tempnam(sys_get_temp_dir(), 'zharp');
        file_put_contents($path, str_repeat("\x01", 100 * 1024));
        $file = new UploadedFile($path, 'me.png', 'image/png', null, true);
        $request = LaravelRequest::create('/upload', 'POST', ['title' => 'Holiday', 'password' => 'upload-secret', 'tags' => ['a', 'b']], [], ['avatar' => $file], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
        $this->laravel($request, static fn () => new JsonResponse(['error' => 'too large'], 413));
        @unlink($path);

        self::assertSame('{"title":"Holiday","password":"[Filtered]","tags":["a","b"],"avatar":"[file me.png · image/png · 100 KB]"}', $this->attr('http.request.body'));
        self::assertSame('multipart/form-data; boundary=x', $this->attr('http.request.header.content-type'));
    }

    public function testBinaryAndStreamedResponsesAreDescribed(): void
    {
        $this->start(['capture_bodies' => 'all']);
        $this->laravel(LaravelRequest::create('/thumb'), static fn () => new Response(str_repeat("\x07", 3000), 200, ['Content-Type' => 'image/png']));
        self::assertSame('[binary · image/png · 3 KB]', $this->attr('http.response.body'));

        $this->start(['capture_bodies' => 'all']);
        $this->laravel(LaravelRequest::create('/feed'), static fn () => new StreamedResponse(static function (): void {
            echo 'data: 1';
        }, 200, ['Content-Type' => 'text/event-stream']));
        self::assertSame('[event stream]', $this->attr('http.response.body'));

        $this->start(['capture_bodies' => 'all']);
        $path = tempnam(sys_get_temp_dir(), 'zharp');
        file_put_contents($path, str_repeat('%', 2048));
        $this->laravel(LaravelRequest::create('/report'), static fn () => new BinaryFileResponse($path, 200, ['Content-Type' => 'application/pdf']));
        @unlink($path);
        self::assertSame('[binary · application/pdf · 2 KB]', $this->attr('http.response.body'));
    }

    public function testGzippedResponseIsInflated(): void
    {
        $this->start(['capture_bodies' => 'errors']);
        $this->laravel(LaravelRequest::create('/pay'), static fn () => new Response((string) gzencode('{"error":"declined","cvv":"123"}'), 502, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']));
        self::assertSame('{"error":"declined","cvv":"[Filtered]"}', $this->attr('http.response.body'));
    }

    public function testUrlsLimitsWhichRequests(): void
    {
        $this->start(['capture_bodies' => ['mode' => 'all', 'urls' => ['#/api/#']]]);
        $this->laravel(LaravelRequest::create('/health'), static fn () => new JsonResponse(['up' => true]));
        self::assertNull($this->attr('http.response.body'));

        $this->start(['capture_bodies' => ['mode' => 'all', 'urls' => ['#/api/#']]]);
        $this->laravel(LaravelRequest::create('/api/orders'), static fn () => new JsonResponse(['n' => 1]));
        self::assertSame('{"n":1}', $this->attr('http.response.body'));
    }

    public function testSecretQueryValuesAreMaskedEvenWithBodiesOff(): void
    {
        $this->start();
        $this->laravel(LaravelRequest::create('/reset?token=abc123&page=2&pwd=x'), static fn () => new Response('ok'));
        self::assertSame('page=2&pwd=[Filtered]&token=[Filtered]', $this->attr('url.query'));
    }

    public function testExceptionEscapingKeepsTheRequestBody(): void
    {
        $this->start(['capture_bodies' => 'errors']);
        $request = LaravelRequest::create('/boom', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"apiKey":"k","q":1}');
        try {
            $this->laravel($request, static function (): Response {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('{"apiKey":"[Filtered]","q":1}', $this->attr('http.request.body'));
        self::assertNull($this->attr('http.response.body'));
    }

    // ── Symfony ───────────────────────────────────────────────────────────────

    public function testSymfonySubscriber(): void
    {
        $this->start(['capture_bodies' => 'errors']);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $request = Request::create('/login', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'user=ada&password=p4ss');
        $subscriber = new RequestSubscriber();
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new JsonResponse(['error' => 'bad credentials'], 401);
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));

        self::assertSame('user=ada&password=[Filtered]', $this->attr('http.request.body'));
        self::assertSame('{"error":"bad credentials"}', $this->attr('http.response.body'));
    }
}
