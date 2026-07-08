<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Fire-and-forget: GET a signed imgproxy URL so the resized derivative lands
 * in imgproxy's S3 result cache before anyone views it. No destination — the
 * response body is never saved, only the side effect of imgproxy processing
 * and caching it matters.
 */
final class WarmImgproxyCacheMessage
{
    public function __construct(
        public readonly string $url,
    ) {
    }
}
