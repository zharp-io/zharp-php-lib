<?php

declare(strict_types=1);

namespace Zharp\Internal;

/**
 * Request and response headers on the request's span, as OpenTelemetry names them
 * (http.request.header.user-agent), so a failed request shows what was sent and what came back —
 * and what wasn't sent (a 401 with no Authorization header). Secret headers are recorded as
 * "[Filtered]": you see one was sent, never its value.
 *
 * @internal
 */
final class Headers
{
    private const SECRET = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-token', 'x-csrf-token', 'x-xsrf-token'];
    private const MAX_HEADERS = 40;
    private const MAX_VALUE = 512;

    /**
     * @param array<string, string|list<string|null>|null> $headers
     * @return array<string, string>
     */
    public static function attributes(string $side, array $headers, ?BodySettings $settings = null): array
    {
        $out = [];
        ksort($headers);
        foreach ($headers as $name => $value) {
            if (count($out) >= self::MAX_HEADERS) {
                break;
            }
            $name = strtolower((string) $name);
            $text = is_array($value) ? implode(', ', array_map('strval', array_filter($value, static fn ($v) => $v !== null))) : (string) $value;
            if ($text === '') {
                continue;
            }
            if (in_array($name, self::SECRET, true) || Bodies::isSecretKey($name, $settings)) {
                $text = Bodies::FILTERED;
            } elseif (strlen($text) > self::MAX_VALUE) {
                $text = substr($text, 0, self::MAX_VALUE) . '…';
            }
            $out['http.' . $side . '.header.' . $name] = $text;
        }

        return $out;
    }
}
