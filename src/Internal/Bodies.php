<?php

declare(strict_types=1);

namespace Zharp\Internal;

use stdClass;

/**
 * Request and response bodies on HTTP spans (`capture_bodies`), and secrets in URLs. The same
 * rules, attribute names and wording as every other Zharp SDK (the JavaScript SDK's
 * internal/bodies.ts is the reference), so the trace view reads them all alike.
 *
 * When bodies are on, they are:
 * - redacted before they leave the process: field names stay, values under keys that look secret
 *   (`password`, `token`, `authorization`, `card`, …) become `[Filtered]`, plus any keys the app adds
 * - kept readable: an inline image or other long base64 value becomes `[image/png · 24 KB]`, a
 *   file in a form upload `[file photo.png · image/png · 240 KB]`, and a body that is not text
 *   at all (an image, a PDF, an event stream) a one-line description of it
 * - capped (32 KB by default), with `http.*.body.truncated` set when they were cut
 *
 * @internal
 */
final class Bodies
{
    public const DEFAULT_MAX_BODY = 32 * 1024;
    public const MAX_BODY = 128 * 1024;
    public const FILTERED = '[Filtered]';

    public const REQUEST = 'http.request.body';
    public const REQUEST_TRUNCATED = 'http.request.body.truncated';
    public const REQUEST_TYPE = 'http.request.header.content-type';
    public const RESPONSE = 'http.response.body';
    public const RESPONSE_TRUNCATED = 'http.response.body.truncated';
    public const RESPONSE_TYPE = 'http.response.header.content-type';

    /** Built-in keys whose values never leave the process, compared without case, `-` or `_`. */
    private const CONTAINS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'apikey', 'accesskey', 'privatekey',
        'authorization', 'cookie', 'creditcard', 'cardnumber', 'cvv', 'cvc', 'ssn', 'iban', 'sessionid',
        'signature', 'credential',
    ];
    private const EXACT = ['auth', 'pin', 'pwd', 'otp', 'card', 'pan', 'jwt', 'sid', 'codeverifier'];
    /** Also secret in a query string, where they are API keys and signed-URL signatures. */
    private const URL_EXACT = ['key', 'sig', 'code', 'state', 'session'];

    private const DATA_URI = '/^data:([\w.+-]+\/[\w.+-]+)?(?:;[\w=.+-]+)*;base64,([A-Za-z0-9+\/=\s]*)$/';
    private const BASE64 = '/^[A-Za-z0-9+\/_-]{256,}={0,2}$/';
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;

    private function __construct()
    {
    }

    // ── settings ──────────────────────────────────────────────────────────────

    /**
     * The `capture_bodies` option as settings, or null when bodies are off. `$env` is
     * ZHARP_CAPTURE_BODIES (`errors`, `all`, `true`; anything else is off).
     */
    public static function settings(mixed $option, ?string $env = null): ?BodySettings
    {
        $value = $option;
        if ($value === null && $env !== null) {
            $e = strtolower(trim($env));
            if ($e === 'errors' || $e === 'all') {
                $value = $e;
            } elseif (in_array($e, ['true', '1', 'yes', 'on'], true)) {
                $value = true;
            }
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            $value = match (true) {
                $v === 'errors', $v === 'all' => $v,
                in_array($v, ['true', '1', 'yes', 'on'], true) => true,
                default => false,
            };
        }
        if (!$value) {
            return null;
        }
        $o = $value === true ? [] : (is_string($value) ? ['mode' => $value] : (is_array($value) ? $value : []));
        $size = $o['max_size'] ?? $o['maxSize'] ?? null;
        $size = is_numeric($size) && (int) $size > 0 ? min((int) $size, self::MAX_BODY) : self::DEFAULT_MAX_BODY;
        $keys = [];
        $patterns = [];
        foreach ((array) ($o['redact_keys'] ?? $o['redactKeys'] ?? []) as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (self::isPattern($key)) {
                $patterns[] = $key;
            } else {
                $keys[] = $key;
            }
        }
        $urls = $o['urls'] ?? null;
        $urls = is_array($urls) ? array_values(array_filter($urls, 'is_string')) : null;

        return new BodySettings(($o['mode'] ?? 'errors') === 'all' ? 'all' : 'errors', $size, $keys, $patterns, $urls);
    }

    /** A string that is a delimited PCRE pattern (`/^account/i`, `#token#`), rather than a key. */
    private static function isPattern(string $value): bool
    {
        if (strlen($value) < 3 || !in_array($value[0], ['/', '#', '~'], true)) {
            return false;
        }

        return @preg_match($value, '') !== false;
    }

    /** Raw text read before shortening and capping: inline images shrink to a few words, so more is read than is kept. */
    public static function rawLimit(BodySettings $settings): int
    {
        return max(64 * 1024, $settings->maxSize * 2);
    }

    /** Whether a request with this outcome has its bodies kept. Status 0 means no response. */
    public static function wanted(BodySettings $settings, int $status): bool
    {
        return $settings->mode === 'all' || $status === 0 || $status >= 400;
    }

    // ── content types ─────────────────────────────────────────────────────────

    private static function mime(?string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType ?? '')[0]));
    }

    public static function isEventStream(?string $contentType): bool
    {
        return self::mime($contentType) === 'text/event-stream';
    }

    public static function isMultipart(?string $contentType): bool
    {
        return self::mime($contentType) === 'multipart/form-data';
    }

    /** Whether a body of this type is text worth showing. An unknown type is given the benefit of the doubt. */
    public static function isTextual(?string $contentType): bool
    {
        $type = self::mime($contentType);
        if ($type === '') {
            return true;
        }
        if ($type === 'text/event-stream') {
            return false;
        }

        return str_starts_with($type, 'text/')
            || str_ends_with($type, '+json')
            || str_ends_with($type, '+xml')
            || preg_match('/^application\/(json|xml|x-www-form-urlencoded|graphql|javascript|x-ndjson|ld\+json)$/', $type) === 1;
    }

    private static function isForm(?string $contentType): bool
    {
        return self::mime($contentType) === 'application/x-www-form-urlencoded';
    }

    /** `240 KB`, `1.4 MB`. Rounds as JavaScript does (half up). */
    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return ((int) floor($bytes / 1024 + 0.5)) . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, '.', '') . ' MB';
    }

    /** How a file in a form upload is shown: `[file photo.png · image/png · 240 KB]`. */
    public static function fileLabel(?string $name, ?string $type, ?int $size, bool $partial = false): string
    {
        $parts = ['file ' . ($name !== null && $name !== '' ? $name : 'blob')];
        if ($type !== null && $type !== '') {
            $parts[] = $type;
        }
        if ($size !== null) {
            $parts[] = self::formatSize($size) . ($partial ? '+' : '');
        }

        return '[' . implode(' · ', $parts) . ']';
    }

    /** What a body that is not text says instead: `[binary · image/png · 24 KB]`, `[event stream]`. */
    public static function describeBody(?string $contentType, ?int $size = null, bool $partial = false): string
    {
        if (self::isEventStream($contentType)) {
            return '[event stream]';
        }
        $parts = ['binary'];
        $type = self::mime($contentType);
        if ($type !== '') {
            $parts[] = $type;
        }
        if ($size !== null && $size > 0) {
            $parts[] = self::formatSize($size) . ($partial ? '+' : '');
        }

        return '[' . implode(' · ', $parts) . ']';
    }

    // ── redaction ─────────────────────────────────────────────────────────────

    private static function normalize(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));
    }

    public static function isSecretKey(string $key, ?BodySettings $settings = null): bool
    {
        $k = self::normalize($key);
        if ($k === '') {
            return false;
        }
        if (in_array($k, self::EXACT, true)) {
            return true;
        }
        foreach (self::CONTAINS as $c) {
            if (str_contains($k, $c)) {
                return true;
            }
        }
        if ($settings !== null) {
            foreach ($settings->extraKeys as $extra) {
                if (self::normalize($extra) === $k) {
                    return true;
                }
            }
            foreach ($settings->extraPatterns as $pattern) {
                if (@preg_match($pattern, $key) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A long base64 value as a short description; other strings as they are. */
    private static function shorten(string $value): string
    {
        if (strlen($value) < 256) {
            return $value;
        }
        if (preg_match(self::DATA_URI, $value, $m) === 1) {
            $type = ($m[1] ?? '') !== '' ? $m[1] : 'data';

            return '[' . $type . ' · ' . self::formatSize(intdiv(strlen($m[2]) * 3, 4)) . ']';
        }
        if (preg_match(self::BASE64, $value) === 1 && preg_match('/[0-9+\/]/', $value) === 1) {
            return '[base64 · ' . self::formatSize(intdiv(strlen($value) * 3, 4)) . ']';
        }

        return $value;
    }

    /** Every value under $value filtered, its keys kept: `{"brand":"[Filtered]","last4":"[Filtered]"}`. */
    private static function filterAll(mixed $value, int $depth): mixed
    {
        if ($depth > 20) {
            return self::FILTERED;
        }
        if ($value === null || $value === '') {
            return $value;
        }
        if (is_array($value)) {
            return array_map(static fn ($v) => self::filterAll($v, $depth + 1), $value);
        }
        if ($value instanceof stdClass) {
            $out = new stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $out->{$k} = self::filterAll($v, $depth + 1);
            }

            return $out;
        }

        return self::FILTERED;
    }

    /** A decoded JSON value (objects as stdClass) with secrets filtered and long base64 shortened. */
    public static function redactValue(mixed $value, ?BodySettings $settings = null, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return self::shorten($value);
        }
        if ($depth > 20 || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            return array_map(static fn ($v) => self::redactValue($v, $settings, $depth + 1), $value);
        }
        if ($value instanceof stdClass) {
            $out = new stdClass();
            foreach (get_object_vars($value) as $k => $v) {
                $out->{$k} = self::isSecretKey((string) $k, $settings) ? self::filterAll($v, $depth + 1) : self::redactValue($v, $settings, $depth + 1);
            }

            return $out;
        }

        return $value;
    }

    /**
     * Text that did not parse (cut-off JSON, a body that only looks like JSON): `"key": value`
     * pairs under secret keys are filtered where they appear, and long base64 runs shortened.
     */
    private static function redactLoose(string $text, ?BodySettings $settings): string
    {
        // A quoted run of base64 (an inline image), possibly cut off before its closing quote.
        $runs = '/"((?:data:[\w.+-]+\/[\w.+-]+(?:;[\w=.+-]+)*;base64,)?[A-Za-z0-9+\/_=-]{256,})("?)/';
        $text = (string) preg_replace_callback($runs, static function (array $m): string {
            $short = self::shorten($m[1]);
            if ($short === $m[1]) {
                return $m[0];
            }

            return $m[2] !== '' ? '"' . $short . '"' : '"' . substr($short, 0, -1) . '+]"';
        }, $text);

        return (string) preg_replace_callback(
            '/("([^"\\\\]{1,100})"\s*:\s*)("(?:[^"\\\\]|\\\\.)*"?|-?\d[\d.eE+-]*|true|false)/',
            static fn (array $m): string => self::isSecretKey($m[2], $settings) ? $m[1] . '"' . self::FILTERED . '"' : $m[0],
            $text,
        );
    }

    private static function redactForm(string $text, ?BodySettings $settings): string
    {
        $pairs = array_map(static function (string $pair) use ($settings): string {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                return $pair;
            }
            $key = rawurldecode(str_replace('+', ' ', substr($pair, 0, $eq)));
            if (self::isSecretKey($key, $settings)) {
                return substr($pair, 0, $eq) . '=' . self::FILTERED;
            }
            $value = substr($pair, $eq + 1);

            return strlen($value) >= 256 ? substr($pair, 0, $eq) . '=' . self::shortenEncoded($value) : $pair;
        }, explode('&', $text));

        return implode('&', $pairs);
    }

    private static function shortenEncoded(string $value): string
    {
        $decoded = rawurldecode(str_replace('+', ' ', $value));
        $short = self::shorten($decoded);

        return $short === $decoded ? $value : $short;
    }

    // ── the attribute value ───────────────────────────────────────────────────

    /** JSON as JavaScript's JSON.stringify writes it. */
    public static function json(mixed $value): string
    {
        $out = json_encode($value, self::JSON_FLAGS);

        return $out === false ? 'null' : $out;
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function cutTo(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
    }

    /** At most $bytes bytes of $text, never ending inside a UTF-8 character. */
    public static function cutBytes(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }

        return function_exists('mb_strcut') ? mb_strcut($text, 0, $bytes, 'UTF-8') : substr($text, 0, $bytes);
    }

    /**
     * A body as it is sent: redacted, then cut to max_size. $cut says the text was already cut
     * short where it was read. Null for an empty body. A multipart/form-data body is passed as
     * the JSON of its fields (files already described).
     *
     * @return array{value: string, truncated: bool}|null
     */
    public static function formatBody(?string $text, ?string $contentType, BodySettings $settings, bool $cut = false): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        $body = $text;
        if (self::isForm($contentType)) {
            $body = self::redactForm($body, $settings);
        } else {
            $trimmed = trim($body);
            $parsed = null;
            $ok = false;
            if (!$cut && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                $parsed = json_decode($trimmed, false, 512);
                $ok = json_last_error() === JSON_ERROR_NONE;
            }
            $body = $ok ? self::json(self::redactValue($parsed, $settings)) : self::redactLoose($body, $settings);
        }
        $length = self::length($body);
        $truncated = $cut || $length > $settings->maxSize;

        return ['value' => $length > $settings->maxSize ? self::cutTo($body, $settings->maxSize) : $body, 'truncated' => $truncated];
    }

    /**
     * The span attributes for one side of a request ('request' or 'response'), or none when the
     * body is empty or not text (describe those with describedAttributes()).
     *
     * @return array<string, string|bool>
     */
    public static function bodyAttributes(string $side, ?string $text, ?string $contentType, BodySettings $settings, bool $cut = false): array
    {
        if (!self::isTextual($contentType) && !self::isMultipart($contentType)) {
            return [];
        }
        $formatted = self::formatBody($text, $contentType, $settings, $cut);
        if ($formatted === null) {
            return [];
        }
        $request = $side === 'request';
        $attributes = [$request ? self::REQUEST : self::RESPONSE => $formatted['value']];
        if ($formatted['truncated']) {
            $attributes[$request ? self::REQUEST_TRUNCATED : self::RESPONSE_TRUNCATED] = true;
        }
        if ($contentType !== null && $contentType !== '') {
            $attributes[$request ? self::REQUEST_TYPE : self::RESPONSE_TYPE] = $contentType;
        }

        return $attributes;
    }

    /**
     * The attributes for a body that is not text: what it was, not what it said.
     *
     * @return array<string, string>
     */
    public static function describedAttributes(string $side, ?string $contentType, ?int $size = null, bool $partial = false): array
    {
        $request = $side === 'request';
        $attributes = [$request ? self::REQUEST : self::RESPONSE => self::describeBody($contentType, $size, $partial)];
        if ($contentType !== null && $contentType !== '') {
            $attributes[$request ? self::REQUEST_TYPE : self::RESPONSE_TYPE] = $contentType;
        }

        return $attributes;
    }

    // ── URLs ──────────────────────────────────────────────────────────────────

    /**
     * A URL as it is recorded: query values under secret-looking keys (`token`, `key`, `sig`,
     * `password`, …) become `[Filtered]`, and `user:password@` is dropped. Anything that is not a
     * URL is returned as it is.
     */
    public static function redactUrl(string $url, ?BodySettings $settings = null): string
    {
        if ($url === '') {
            return $url;
        }
        $out = (string) preg_replace('/^([a-z][a-z0-9+.-]*:\/\/)[^\/?#@]*@/i', '$1', $url);
        $q = strpos($out, '?');
        if ($q === false) {
            return $out;
        }
        $hash = strpos($out, '#', $q);
        $query = $hash === false ? substr($out, $q + 1) : substr($out, $q + 1, $hash - $q - 1);
        $redacted = self::redactQuery($query, $settings);
        if ($redacted !== $query) {
            $out = substr($out, 0, $q + 1) . $redacted . ($hash === false ? '' : substr($out, $hash));
        }

        return $out;
    }

    /** A query string (without `?`) with secret-looking values filtered. */
    public static function redactQuery(string $query, ?BodySettings $settings = null): string
    {
        if ($query === '') {
            return $query;
        }
        $pairs = array_map(static function (string $pair) use ($settings): string {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                return $pair;
            }
            $key = rawurldecode(str_replace('+', ' ', substr($pair, 0, $eq)));
            $secret = self::isSecretKey($key, $settings) || in_array(self::normalize($key), self::URL_EXACT, true);

            return $secret && $eq < strlen($pair) - 1 ? substr($pair, 0, $eq) . '=' . self::FILTERED : $pair;
        }, explode('&', $query));

        return implode('&', $pairs);
    }

    /**
     * Whether $url matches one of $patterns: a bare host (with or without a port) matches that
     * host; a string with a scheme matches as a prefix; a delimited regex is tested against the URL.
     *
     * @param list<string> $patterns
     */
    public static function urlMatches(string $url, array $patterns): bool
    {
        $parts = parse_url($url);
        $hostname = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $host = $hostname !== '' && isset($parts['port']) ? $hostname . ':' . $parts['port'] : $hostname;
        foreach ($patterns as $p) {
            if ($p === '') {
                continue;
            }
            if (self::isPattern($p)) {
                if (@preg_match($p, $url) === 1) {
                    return true;
                }
            } elseif (str_contains($p, '://')) {
                if (str_starts_with($url, $p)) {
                    return true;
                }
            } elseif (strtolower($p) === $host || strtolower($p) === $hostname) {
                return true;
            }
        }

        return false;
    }
}
