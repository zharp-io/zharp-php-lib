<?php

declare(strict_types=1);

namespace Zharp\Internal;

/**
 * `capture_bodies`, resolved. See Bodies::settings().
 *
 * @internal
 */
final class BodySettings
{
    /**
     * @param 'errors'|'all' $mode
     * @param list<string> $extraKeys keys whose values are filtered, compared like the built-in ones
     * @param list<string> $extraPatterns delimited regexes tested against keys as they are
     * @param list<string>|null $urls which requests have their bodies captured; null = all
     */
    public function __construct(
        public readonly string $mode,
        public readonly int $maxSize,
        public readonly array $extraKeys,
        public readonly array $extraPatterns,
        public readonly ?array $urls,
    ) {
    }

    /** Whether this request's bodies may be captured at all. */
    public function allows(string $url): bool
    {
        return $this->urls === null || Bodies::urlMatches($url, $this->urls);
    }
}
