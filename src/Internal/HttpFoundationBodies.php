<?php

declare(strict_types=1);

namespace Zharp\Internal;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Request and response bodies from Symfony HttpFoundation objects (Laravel's Request and
 * Response are those too), as span attributes. Reads only what PHP already holds: the request's
 * body (php://input, buffered by PHP), the upload's text fields and file names/types/sizes —
 * never a file's contents — and the response's content string. Streamed and file responses are
 * described, never read. Nothing the app reads or sends is changed.
 *
 * @internal
 */
final class HttpFoundationBodies
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string|bool>
     */
    public static function attributes(Request $request, ?Response $response, BodySettings $settings): array
    {
        $attributes = [];
        try {
            $attributes += self::request($request, $settings);
        } catch (Throwable) {
        }
        if ($response !== null) {
            try {
                $attributes += self::response($response, $settings);
            } catch (Throwable) {
            }
        }

        return $attributes;
    }

    /** @return array<string, string|bool> */
    private static function request(Request $request, BodySettings $settings): array
    {
        $type = self::header($request->headers->get('Content-Type'));
        if (Bodies::isMultipart($type)) {
            // PHP has already parsed the upload: text fields in $_POST, files in $_FILES.
            $fields = self::fields($request->request->all());
            foreach (self::files($request->files->all()) as $name => $file) {
                $fields[$name] = $file;
            }
            if ($fields === []) {
                return [];
            }

            return Bodies::bodyAttributes('request', Bodies::json((object) $fields), $type, $settings);
        }
        $length = self::length($request->headers->get('Content-Length'));
        if (!Bodies::isTextual($type)) {
            return $length > 0 || $length === null ? self::describedIfAny('request', $type, $length, $request) : [];
        }
        $content = $request->getContent();
        if (!is_string($content) || $content === '') {
            return [];
        }
        $limit = Bodies::rawLimit($settings);
        $cut = strlen($content) > $limit;

        return Bodies::bodyAttributes('request', $cut ? Bodies::cutBytes($content, $limit) : $content, $type, $settings, $cut);
    }

    /** @return array<string, string|bool> */
    private static function response(Response $response, BodySettings $settings): array
    {
        $type = self::header($response->headers->get('Content-Type'));
        if ($response instanceof BinaryFileResponse) {
            $size = null;
            try {
                $size = $response->getFile()->getSize();
            } catch (Throwable) {
            }

            return Bodies::describedAttributes('response', $type ?? $response->getFile()->getMimeType(), is_int($size) ? $size : null);
        }
        if ($response instanceof StreamedResponse || (class_exists(StreamedJsonResponse::class) && $response instanceof StreamedJsonResponse)) {
            return Bodies::describedAttributes('response', $type, self::length($response->headers->get('Content-Length')));
        }
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return [];
        }
        $encoding = strtolower(trim((string) self::header($response->headers->get('Content-Encoding'))));
        if ($encoding !== '' && $encoding !== 'identity') {
            $inflated = self::inflate($content, $encoding);
            if ($inflated === null) {
                return Bodies::describedAttributes('response', $type, strlen($content));
            }
            $content = $inflated;
        }
        if (!Bodies::isTextual($type)) {
            return Bodies::describedAttributes('response', $type, strlen($content));
        }
        $limit = Bodies::rawLimit($settings);
        $cut = strlen($content) > $limit;

        return Bodies::bodyAttributes('response', $cut ? Bodies::cutBytes($content, $limit) : $content, $type, $settings, $cut);
    }

    /**
     * A request body that is not text: described by its declared length. Without one, PHP has the
     * body in memory already, so its length is read (not its contents).
     *
     * @return array<string, string>
     */
    private static function describedIfAny(string $side, ?string $type, ?int $length, Request $request): array
    {
        if ($length === null) {
            $content = $request->getContent();
            $length = is_string($content) ? strlen($content) : 0;
            if ($length === 0) {
                return [];
            }
        }

        return Bodies::describedAttributes($side, $type, $length);
    }

    private static function inflate(string $content, string $encoding): ?string
    {
        $out = match ($encoding) {
            'gzip', 'x-gzip' => function_exists('gzdecode') ? @gzdecode($content) : false,
            'deflate' => function_exists('gzuncompress') ? (@gzuncompress($content) ?: (function_exists('gzinflate') ? @gzinflate($content) : false)) : false,
            default => false,
        };

        return is_string($out) ? $out : null;
    }

    /**
     * Text fields as the form sent them (`tags[]` stays a list, `address[city]` an object).
     *
     * @param array<mixed> $fields
     * @return array<string, mixed>
     */
    private static function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $value) {
            $out[(string) $name] = is_array($value) ? (array_is_list($value) ? array_values($value) : (object) self::fields($value)) : (is_scalar($value) ? (string) $value : $value);
        }

        return $out;
    }

    /**
     * Files as `[file name · type · size]`, nested the way they were named.
     *
     * @param array<mixed> $files
     * @return array<string, mixed>
     */
    private static function files(array $files): array
    {
        $out = [];
        foreach ($files as $name => $file) {
            if ($file instanceof UploadedFile) {
                $size = $file->getSize();
                $out[(string) $name] = Bodies::fileLabel($file->getClientOriginalName(), $file->getClientMimeType(), is_int($size) ? $size : null);
            } elseif (is_array($file)) {
                $nested = self::files($file);
                $out[(string) $name] = array_is_list($file) ? array_values($nested) : (object) $nested;
            }
        }

        return $out;
    }

    private static function header(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function length(mixed $value): ?int
    {
        $value = self::header($value);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
