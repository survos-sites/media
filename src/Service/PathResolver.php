<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetPath;

/**
 * Builds relative paths that Liip's Flysystem loader understands.
 * For locals (Liip), we use: o/<dir3>/<hex>.<ext>
 * For variants, let your Variant workflow write to archive/CDN using ShardedKey.
 */
final class PathResolver
{
    public const LOCAL_ORIG_PREFIX = 'o';

    public function localOriginalRelative(Asset $asset): string
    {
        if (!$asset->localDir instanceof AssetPath) {
            throw new \RuntimeException('Asset has no assigned local directory (AssetPath).');
        }
        $hex = $asset->contentHashHex();
        $ext = $asset->ext ?: 'jpg';
        return sprintf('%s/%s/%s.%s', self::LOCAL_ORIG_PREFIX, $asset->localDir->dir3, $hex, $ext);
    }

    /**
     * Convenience for making sure the containing directory exists under a given ABSOLUTE root
     * (your local.storage root on disk) if you need a fully-qualified path for non-Liip IO.
     */
    public function absoluteFromLocalRoot(string $localRoot, string $relative): string
    {
        return rtrim($localRoot, '/').'/'.$relative;
    }
}
