<?php

namespace Zharp\Logs\Internal;

use Zharp\Logs\Zharp;

/**
 * Sends batches of log entries to {endpoint}/agent/logs as {"logs": [...]}.
 *
 * PHP sends in the request that logged, so every send is bounded by a deadline: a slow or
 * unreachable Zharp costs a request at most that long, never more.
 *
 * @internal
 */
class Transport
{
    public const DEFAULT_ENDPOINT = 'https://api.zharp.io/api/v1';

    /**
     * Largest body one request carries. The backend accepts 10 MB; staying far below it keeps one
     * slow upload from holding everything behind it. An entry larger than this on its own is still
     * sent alone rather than dropped.
     */
    public const MAX_REQUEST_BYTES = 1000000;

    /**
     * Seconds sending stops once Zharp refuses the API key (401/403). One body is tried again
     * after it; while the key is still refused the wait starts over.
     */
    public const REJECTED_BACKOFF = 3600;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $url;
    /** @var float The first retry waits twice this, the second four times. */
    private $retryDelay;

    public function __construct(string $apiKey, string $endpoint, float $retryDelay = 0.25)
    {
        $this->apiKey = $apiKey;
        $this->url = $endpoint . '/agent/logs';
        $this->retryDelay = $retryDelay;
    }

    public static function normalizeEndpoint(?string $endpoint): string
    {
        $value = $endpoint === null || trim($endpoint) === '' ? self::DEFAULT_ENDPOINT : trim($endpoint);

        return rtrim($value, '/');
    }

    /**
     * Send entries, split into bodies by size, giving up on what's left at the deadline. Returns
     * whether every body was taken or refused for good (a 4xx).
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public function send(array $entries, float $deadline): bool
    {
        $delivered = true;
        foreach (self::splitBySize($entries, self::MAX_REQUEST_BYTES) as $body) {
            // While the key is refused, entries are dropped: nothing queues up.
            if ($this->backedOff()) {
                return true;
            }
            if (!$this->sendBody($body, $deadline)) {
                $delivered = false;
            }
        }

        return $delivered;
    }

    /**
     * Three attempts in all, within the deadline. Only network errors and 5xx responses are retried
     * — a 4xx won't be fixed by sending the same thing again.
     */
    private function sendBody(string $body, float $deadline): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $left = $deadline - microtime(true);
            if ($left <= 0.05) {
                return false;
            }
            $status = $this->post($body, min(10.0, $left));
            if ($status === 401 || $status === 403) {
                $this->refused($status);

                return true;
            }
            if ($status > 0 && $status < 500) {
                if ($status < 300) {
                    $this->accepted();
                }

                return true;
            }
            $wait = $this->retryDelay * (2 << $attempt);
            if ($attempt < 2 && microtime(true) + $wait < $deadline) {
                usleep((int) ($wait * 1000000));
            }
        }

        return false;
    }

    /**
     * Where a refusal is remembered: a file holding the time to try again. PHP keeps nothing
     * between requests, so without it every request would send to the dead key and warn again.
     */
    public function rejectionMarker(): string
    {
        return sys_get_temp_dir() . '/zharp-rejected-' . substr(sha1($this->apiKey . '|' . $this->url), 0, 16);
    }

    /** Whether sending is stopped because Zharp refused the key; once the wait is over, one body goes. */
    public function backedOff(): bool
    {
        $marker = $this->rejectionMarker();
        if (!is_file($marker)) {
            return false;
        }

        return microtime(true) < (float) @file_get_contents($marker);
    }

    /** Stops sending for REJECTED_BACKOFF; the warning is written once, not on every retry. */
    private function refused(int $status): void
    {
        $marker = $this->rejectionMarker();
        if (!is_file($marker)) {
            self::warn("Zharp rejected this API key (HTTP $status) — logs are not being sent. Check ZHARP_API_KEY.");
        }
        @file_put_contents($marker, (string) (microtime(true) + self::REJECTED_BACKOFF), LOCK_EX);
    }

    /** Resumes sending after a refusal, and says so once. */
    private function accepted(): void
    {
        $marker = $this->rejectionMarker();
        if (is_file($marker) && @unlink($marker)) {
            self::warn('Zharp accepted the API key again — logs are being sent.');
        }
    }

    /** On stderr in a script, in the error log on a web server — where someone will see it. */
    private static function warn(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            Env::announce($message);
        } else {
            error_log('[Zharp] ' . $message);
        }
    }

    /** One POST; the status code, or 0 when it never got one. */
    public function post(string $body, float $timeout): int
    {
        $headers = [
            'Content-Type: application/json',
            // The key goes in a header, never the URL: URLs end up in proxy logs.
            'X-API-KEY: ' . $this->apiKey,
            'X-Zharp-SDK: ' . Zharp::SDK_NAME,
            'User-Agent: ' . Zharp::SDK_NAME,
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($this->url);
            if ($ch === false) {
                return 0;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min(3.0, $timeout) * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_NOSIGNAL => true,
            ]);
            $ok = curl_exec($ch);
            $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return $status;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);
        $stream = @fopen($this->url, 'r', false, $context);
        if ($stream === false) {
            return 0;
        }
        $meta = stream_get_meta_data($stream);
        fclose($stream);
        foreach ($meta['wrapper_data'] ?? [] as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status ?? 0;
    }

    /**
     * {"logs": [...]} bodies of at most $limit bytes each. An entry too large to fit even alone
     * still gets a body of its own: whether it can be delivered is for the network to decide.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return string[]
     */
    public static function splitBySize(array $entries, int $limit): array
    {
        $overhead = strlen('{"logs":[]}');
        $bodies = [];
        $group = [];
        $size = $overhead;
        foreach ($entries as $entry) {
            $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                continue;
            }
            $entrySize = strlen($json) + 1; // +1 for the separating comma
            if ($group && $size + $entrySize > $limit) {
                $bodies[] = '{"logs":[' . implode(',', $group) . ']}';
                $group = [];
                $size = $overhead;
            }
            $group[] = $json;
            $size += $entrySize;
        }
        if ($group) {
            $bodies[] = '{"logs":[' . implode(',', $group) . ']}';
        }

        return $bodies;
    }
}
