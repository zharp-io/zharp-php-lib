<?php

declare(strict_types=1);

namespace Zharp\Internal;

use php_user_filter;
use Throwable;

/**
 * A write filter that copies what passes through a console stream to OutputCapture. The bytes
 * themselves go on unchanged.
 *
 * @internal
 */
final class OutputFilter extends php_user_filter
{
    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            try {
                if (is_array($this->params)) {
                    OutputCapture::fromStream((string) $this->params['key'], (string) $this->params['source'], (string) $bucket->data);
                }
            } catch (Throwable) {
                // A line that could not be copied is not worth breaking the write.
            }
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
