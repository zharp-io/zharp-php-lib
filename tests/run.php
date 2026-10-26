<?php

// The tests, with nothing to install: `php tests/run.php`. A fake Zharp runs on PHP's built-in
// server; real child processes check what only a real process shows (exit, crashes, fatal errors).
// The Monolog test runs when `composer install` has put Monolog in vendor/.

declare(strict_types=1);

$root = dirname(__DIR__);
require __DIR__ . '/autoload.php';

use Zharp\Logs\Internal\Message;
use Zharp\Logs\Internal\Transport;
use Zharp\Logs\Zharp;

// ── The fake Zharp ──────────────────────────────────────────────────────────────────────────────

$work = sys_get_temp_dir() . '/zharp-php-tests-' . getmypid();
@mkdir($work);
$outFile = $work . '/received.jsonl';
$statusFile = $work . '/status';
$port = 18000 + random_int(0, 999);
putenv("ZHARP_FAKE_OUT=$outFile");
putenv("ZHARP_FAKE_STATUS=$statusFile");
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake-zharp.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'a'], 2 => ['file', $work . '/server.log', 'a']],
    $pipes
);
$endpoint = "http://127.0.0.1:$port/api/v1/";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
register_shutdown_function(static function () use ($server, $work): void {
    proc_terminate($server);
    array_map('unlink', glob($work . '/*') ?: []);
    @rmdir($work);
});

function received(): array
{
    global $outFile;
    if (!is_file($outFile)) {
        return [];
    }
    $requests = [];
    foreach (file($outFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $requests[] = json_decode($line, true);
    }

    return $requests;
}

function logs(): array
{
    $all = [];
    foreach (received() as $request) {
        foreach ($request['body']['logs'] ?? [] as $log) {
            $all[] = $log;
        }
    }

    return $all;
}

function reset_fake(int $status = 201): void
{
    global $outFile, $statusFile;
    @unlink($outFile);
    file_put_contents($statusFile, (string) $status);
}

function start(array $options = []): void
{
    global $endpoint;
    foreach (['ZHARP_API_KEY', 'ZHARP_SERVICE', 'ZHARP_ENV', 'ZHARP_ENDPOINT', 'ZHARP_DISABLED', 'ZHARP_VERSION'] as $name) {
        putenv($name);
    }
    Zharp::resetForTests();
    Zharp::init($options + [
        'api_key' => 'test-key',
        'service' => 'checkout-api',
        'environment' => 'production',
        'app_version' => '2.4.0',
        'endpoint' => $endpoint,
        'capture_exceptions' => false,
    ]);
}

/** Run a PHP script in its own process, with the SDK loaded and started. */
function run_script(string $body, array $options = []): array
{
    global $endpoint, $work, $root;
    $file = $work . '/script-' . random_int(0, PHP_INT_MAX) . '.php';
    $opts = var_export($options + ['api_key' => 'k', 'service' => 'script', 'endpoint' => $endpoint], true);
    file_put_contents($file, "<?php\nrequire " . var_export($root . '/tests/autoload.php', true) . ";\n\\Zharp\\Logs\\Zharp::init($opts);\n" . $body);
    $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    $code = proc_close($process);

    return [$code, $stdout . $stderr];
}

// ── A tiny runner ───────────────────────────────────────────────────────────────────────────────

$tests = [];
function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException($what);
    }
}

function same($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: expected %s, got %s', $what ?: 'value', var_export($expected, true), var_export($actual, true)));
    }
}

// ── The tests ───────────────────────────────────────────────────────────────────────────────────

test('sends the shape every Zharp SDK sends', static function (): void {
    reset_fake();
    start();
    Zharp::setContext(['region' => 'eu']);
    Zharp::info('User signed in', ['user_id' => 42]);
    check(count(logs()) === 0, 'a web-style log waits for the end of the request (or a flush)');
    Zharp::flush();
    $log = logs()[0];

    same('checkout-api', $log['service'], 'service');
    same('info', $log['level'], 'level');
    same('User signed in', $log['message'], 'message');
    check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $log['timestamp']) === 1, 'timestamp ' . $log['timestamp']);
    same('production', $log['environment'], 'environment');
    same('2.4.0', $log['version'], 'version');
    check(!isset($log['source']), 'source sent though unset');
    $meta = $log['metadata'];
    same(42, $meta['user_id'], 'field');
    same(Zharp::sessionId(), $meta['sessionId'], 'session');
    same('eu', $meta['context']['region'], 'context');
    same('2.4.0', $meta['context']['appVersion'], 'appVersion');
    foreach (['hostname', 'pid', 'phpVersion', 'platform', 'sapi'] as $key) {
        check(isset($meta['context'][$key]), "context lacks $key");
    }
    $headers = received()[0]['headers'];
    same('test-key', $headers['x-api-key'], 'key header');
    same('php-logs/' . Zharp::VERSION, $headers['x-zharp-sdk'], 'sdk header');
    same('/api/v1/agent/logs', received()[0]['path'], 'path');
});

test('a caught exception brings its type and stack, previous ones included', static function (): void {
    reset_fake();
    start();
    try {
        try {
            throw new InvalidArgumentException('missing card');
        } catch (InvalidArgumentException $cause) {
            throw new RuntimeException('card declined', 0, $cause);
        }
    } catch (RuntimeException $e) {
        Zharp::error($e);
        Zharp::error('Payment failed', ['order_id' => 99, 'exception' => $e]);
        Zharp::captureException($e);
        Zharp::warn('Retrying', ['err' => $e]);
    }
    Zharp::flush();
    $logs = logs();
    same(4, count($logs), 'count');
    same('RuntimeException: card declined', $logs[0]['message'], 'message');
    same('RuntimeException', $logs[0]['metadata']['name'], 'name');
    $stack = $logs[0]['metadata']['stack'];
    check(strpos($stack, 'run.php') !== false, 'stack has the file');
    check(strpos($stack, 'Previous exception') !== false && strpos($stack, 'missing card') !== false, 'stack has the cause');
    same('Payment failed', $logs[1]['message'], 'message 2');
    same(99, $logs[1]['metadata']['order_id'], 'field');
    same('RuntimeException', $logs[1]['metadata']['exception']['name'], 'exception field');
    same('error', $logs[2]['level'], 'captured');
    same('warn', $logs[3]['level'], 'warn');
    check(isset($logs[3]['metadata']['stack']), 'an exception among the fields brings the stack');
});

test('a stack the caller gave wins', static function (): void {
    start();
    $entry = Zharp::buildEntry('error', 'boom', ['stack' => 'my own'], new RuntimeException('x'), null, 0.0);
    same('my own', $entry['metadata']['stack'], 'stack');
    same('1970-01-01T00:00:00.000Z', $entry['timestamp'], 'timestamp');
});

test('one log can go under another service; PSR-3 level names work', static function (): void {
    reset_fake();
    start();
    Zharp::log('warning', 'Slow', [], ' payments ');
    Zharp::log('critical', 'Down');
    Zharp::log('notice', 'Note');
    Zharp::flush();
    $logs = logs();
    same('payments', $logs[0]['service'], 'service');
    same(['warn', 'fatal', 'info'], array_column($logs, 'level'), 'levels');
});

test('every message becomes text', static function (): void {
    same('(empty message)', Message::toMessage(null));
    same('(empty message)', Message::toMessage('  '));
    same('42', Message::toMessage(42));
    same('{"a":1}', Message::toMessage(['a' => 1]));
    same('LogicException', Message::toMessage(new LogicException()));
    same('true', Message::toMessage(true));
});

test('context JSON cannot hold becomes text', static function (): void {
    $loop = new stdClass();
    $loop->self = $loop;
    $fields = Message::toJsonMap([
        'nan' => NAN,
        'when' => new DateTimeImmutable('2026-10-26 09:00:00', new DateTimeZone('UTC')),
        'bad' => "caf\xE9",
        'loop' => $loop,
        'res' => fopen('php://memory', 'r'),
    ]);
    check(json_encode($fields) !== false, 'all of it encodes');
    same('NAN', $fields['nan'], 'nan');
    same('2026-10-26T09:00:00.000Z', $fields['when'], 'date');
    same('resource(stream)', $fields['res'], 'resource');
});

test('settings come from the environment', static function (): void {
    global $endpoint;
    reset_fake();
    Zharp::resetForTests();
    putenv('ZHARP_API_KEY=env-key');
    putenv('ZHARP_SERVICE=from-env');
    putenv('ZHARP_ENV=staging');
    putenv("ZHARP_ENDPOINT=$endpoint");
    Zharp::init(['capture_exceptions' => false]);
    Zharp::info('hello');
    Zharp::flush();
    $log = logs()[0];
    same('from-env', $log['service'], 'service');
    same('staging', $log['environment'], 'environment');
    same('env-key', received()[0]['headers']['x-api-key'], 'key');
    foreach (['ZHARP_API_KEY', 'ZHARP_SERVICE', 'ZHARP_ENV', 'ZHARP_ENDPOINT'] as $name) {
        putenv($name);
    }
});

test('nothing starts without a key, or when disabled', static function (): void {
    global $endpoint;
    reset_fake();
    Zharp::resetForTests();
    Zharp::init(['service' => 'x', 'endpoint' => $endpoint]);
    check(!Zharp::isActive(), 'started without a key');
    putenv('ZHARP_DISABLED=true');
    Zharp::init(['api_key' => 'k', 'service' => 'x', 'endpoint' => $endpoint]);
    putenv('ZHARP_DISABLED');
    check(!Zharp::isActive(), 'started though disabled');
    Zharp::error('dropped');
    Zharp::flush();
    same([], logs(), 'nothing sent');
});

test('server errors are retried and refusals are not', static function (): void {
    global $endpoint;
    $transport = new Transport('k', Transport::normalizeEndpoint($endpoint), 0.001);
    reset_fake(503);
    check(!$transport->send([['m' => 1]], microtime(true) + 10), '5xx reported as delivered');
    same(3, count(received()), '5xx attempts');
    reset_fake(429);
    check($transport->send([['m' => 1]], microtime(true) + 10), '4xx should count as done');
    same(1, count(received()), '4xx attempts');
    reset_fake();
});

test('bodies are cut by size, and a large entry goes alone', static function (): void {
    $entries = [['m' => str_repeat('x', 40)], ['m' => str_repeat('x', 40)], ['m' => str_repeat('x', 40)], ['m' => str_repeat('y', 500)]];
    $bodies = Transport::splitBySize($entries, 120);
    $total = 0;
    foreach ($bodies as $body) {
        $total += count(json_decode($body, true)['logs']);
    }
    same(4, $total, 'entries');
    same(1, count(json_decode(end($bodies), true)['logs']), 'large one alone');
});

test('the queue keeps the newest 500', static function (): void {
    start(['flush_interval' => 3600, 'max_batch_size' => 10000]);
    // A CLI process sends when a batch fills; with a huge batch size nothing goes yet.
    for ($i = 0; $i < 600; $i++) {
        Zharp::debug("n$i");
    }
    $queued = Zharp::queued();
    same(Zharp::MAX_QUEUED, count($queued), 'size');
    same('n100', $queued[0]['message'], 'oldest kept');
    Zharp::resetForTests();
});

test('in a long-running process an error goes at once', static function (): void {
    reset_fake();
    [$code] = run_script("\\Zharp\\Logs\\Zharp::info('queued');\n\\Zharp\\Logs\\Zharp::error('now');\nsleep(1);\nfile_put_contents('php://stderr', 'still running');\nexit(0);\n");
    same(0, $code, 'exit');
    same(['queued', 'now'], array_column(logs(), 'message'), 'sent');
});

test('a script sends what is left when it ends, and ends promptly', static function (): void {
    reset_fake();
    $started = microtime(true);
    [$code] = run_script("\\Zharp\\Logs\\Zharp::info('last words');\n");
    same(0, $code, 'exit');
    same(['last words'], array_column(logs(), 'message'), 'sent at shutdown');
    check(microtime(true) - $started < 5, 'took too long');
});

test('an uncaught exception is sent and still ends the script', static function (): void {
    reset_fake();
    [$code, $output] = run_script("function charge() { throw new RuntimeException('card declined'); }\ncharge();\n", ['capture_exceptions' => true]);
    same(255, $code, 'exit code');
    check(strpos($output, 'Uncaught RuntimeException: card declined') !== false, 'PHP still reports it: ' . $output);
    $logs = logs();
    same(1, count($logs), 'one log');
    same('fatal', $logs[0]['level'], 'level');
    same('uncaughtException', $logs[0]['metadata']['type'], 'type');
    check(strpos($logs[0]['metadata']['stack'], 'charge()') !== false, 'stack');
});

test('a fatal error is sent', static function (): void {
    reset_fake();
    [$code] = run_script("ini_set('memory_limit', '8M');\n\$a = [];\nwhile (true) { \$a[] = str_repeat('x', 1024 * 1024); }\n", ['capture_exceptions' => true]);
    check($code !== 0, 'the script should have died');
    $logs = logs();
    same(1, count($logs), 'one log');
    same('fatal', $logs[0]['level'], 'level');
    same('fatalError', $logs[0]['metadata']['type'], 'type');
    check(strpos($logs[0]['message'], 'memory size') !== false, 'message ' . $logs[0]['message']);
});

test('Monolog records go through the handler', static function (): void {
    if (!class_exists(\Monolog\Logger::class)) {
        throw new LogicException('skip: Monolog not installed (composer install)');
    }
    reset_fake();
    start();
    $logger = new \Monolog\Logger('shop', [new \Zharp\Logs\Monolog\ZharpHandler(200)]);
    $logger->debug('not sent');
    $logger->warning('Stock low', ['sku' => 'SKU-1']);
    $logger->error('Pricing failed', ['exception' => new DomainException('no price')]);
    $logger->critical('Down');
    Zharp::flush();
    $logs = logs();
    same(['warn', 'error', 'fatal'], array_column($logs, 'level'), 'levels');
    same('SKU-1', $logs[0]['metadata']['sku'], 'context');
    same('shop', $logs[0]['metadata']['channel'], 'channel');
    same('DomainException', $logs[1]['metadata']['name'], 'exception');
});

// ── Run ─────────────────────────────────────────────────────────────────────────────────────────

$failed = 0;
$skipped = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "ok    $name\n";
    } catch (LogicException $e) {
        if (strpos($e->getMessage(), 'skip:') === 0) {
            $skipped++;
            echo "skip  $name — " . substr($e->getMessage(), 6) . "\n";
            continue;
        }
        $failed++;
        echo "FAIL  $name\n      " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL  $name\n      " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
printf("\n%d passed, %d failed, %d skipped\n", count($tests) - $failed - $skipped, $failed, $skipped);
Zharp::resetForTests();
exit($failed ? 1 : 0);
