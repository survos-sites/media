<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use League\ColorExtractor\ColorExtractor;
use League\ColorExtractor\Palette;
use Liip\ImagineBundle\Imagine\Cache\Helper\PathHelper;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Survos\ThumbHashBundle\Service\Thumbhash;

/**
 * Builds image variants (LiipImagine filters) and performs analysis (blurhash/thumbhash, palettes, pHash)
 * off the cached thumbnail file, in one place. Safe to call from workflow steps or controllers
 * as long as the original/source image is available to Liip’s data loaders.
 *
 * Typical usage:
 *   $svc->processPresets($asset, ['/uploads/originals/foo.jpg'], ['small','medium']);
 *
 * Notes:
 * - $sourceUrlPath MUST be a path that LiipImagine understands (web path, not filesystem),
 *   e.g. "/uploads/originals/foo.jpg". We then resolve the cached file under public/.
 * - This service does not flush() — the caller decides transaction boundaries.
 */
final class AssetPreviewService
{
    public function __construct(
        private readonly FilterService $filterService,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ThumbHashService $thumbHashService,
        private readonly ColorAnalysisService $colorAnalysisService,
        private readonly string $publicDir = __DIR__ . '/../../public',
    ) {}

    /**
     * Process multiple presets against a single source URL path.
     *
     * @param Asset          $asset
     * @param string         $sourceUrlPath Web path Liip can load (e.g. "/uploads/originals/foo.jpg")
     * @param list<string>   $presets       e.g. ['small','medium']
     * @return array<string,array{url:string,path:string,width:int,height:int,bytes:int}>
     */
    public function processPresets(Asset $asset, string $sourceUrlPath, array $presets): array
    {
        $results = [];
        foreach ($presets as $preset) {
            try {
                $results[$preset] = $this->processSingle($asset, $sourceUrlPath, $preset);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Preset "%s" failed for %s: %s',
                    $preset, $asset->id, $e->getMessage()));
            }
        }
        return $results;
    }

    /**
     * Build a single LiipImagine variant and run analyses that depend on the thumbnail file.
     *
     * @param Asset  $asset
     * @param string $sourceUrlPath Web path Liip can load (e.g. "/uploads/originals/foo.jpg")
     * @param string $preset        Liip filter name, e.g. "small" or "medium"
     * @return array{url:string,path:string,width:int,height:int,bytes:int}
     */
    public function processSingle(Asset $asset, string $sourceUrlPath, string $preset): array
    {

        // Resolve & build the cached variant; this triggers generation if missing
        $this->logger->debug(sprintf('LiipImagine: %s => %s', $sourceUrlPath, $preset));

        // This returns a URL (may be a /resolve); we call again to get cached URL
        $resolveUrl = $this->filterService->getUrlOfFilteredImage(
            path: $sourceUrlPath,
            filter: $preset,
            resolver: null,
            webpSupported: true
        );

        $cachedUrl = $this->filterService->getUrlOfFilteredImage(
            $sourceUrlPath,
            $preset,
            null,
            true // return cached URL
        );

        $this->logger->debug('Liip variant resolved', [
            'assetId' => $asset->id,
            'preset' => $preset,
            'resolveUrl' => $resolveUrl,
            'cachedUrl' => $cachedUrl,
        ]);

        $cachedPath = $this->publicDir . (string) parse_url($cachedUrl, PHP_URL_PATH);
        if (!is_file($cachedPath)) {
            // Some setups prefer PathHelper conversion:
            // $cachedPath = PathHelper::urlPathToFilePath($cachedUrl);
            throw new \RuntimeException("Cached variant not found at {$cachedPath}");
        }

        // Read & inspect the cached file
        $bytes = (int) filesize($cachedPath);
        $content = file_get_contents($cachedPath);
        if ($content === false) {
            throw new \RuntimeException("Failed reading cached variant: {$cachedPath}");
        }

        $img = new \Imagick();
        $img->readImageBlob($content);
        $w = $img->getImageWidth();
        $h = $img->getImageHeight();

        // Opportunistically set dimensions on Asset if unknown
        if ($asset->width === null || $asset->height === null) {
            $asset->width = $w;
            $asset->height = $h;
        }

        // Do analyses that depend on the thumbnail file
        $this->maybeComputeThumbhash($asset, $preset, $content, $w, $h);
        $this->maybeComputePaletteAndPhash($asset, $preset, $cachedPath);

        return [
            'url'    => $cachedUrl,
            'path'   => $cachedPath,
            'width'  => $w,
            'height' => $h,
            'bytes'  => $bytes,
        ];
    }

    /**
     * @return array{0:int,1:int,2:list<int|float>}
     */
    public function resizeForThumbHashFromUrl(string $imageUrl, int $size = 100): array
    {
        $image = new \Imagick();
        $image->readImage($imageUrl);
        $image->thumbnailImage($size, $size, true);

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $image->getImagePixelColor($x, $y);
                $colors = $pixel->getColor(2);
                $pixels[] = $colors['r'];
                $pixels[] = $colors['g'];
                $pixels[] = $colors['b'];
                $pixels[] = $colors['a'];
            }
        }

        return [$width, $height, $pixels];
    }

    public function maybeComputeThumbhash(Asset $asset, string $preset, string $content): void
    {
        // Convention: compute ThumbHash on the "small" preset
        if ($preset !== 'small') {
            return;
        }

        // Extract pixels in RGBA and build ThumbHash
        [$tw, $th, $pixels] = $this->thumbHashService->extract_size_and_pixels_with_imagick($content);
//        if (!$tw || !$th) {
//            // Fallback to provided (w,h) if extractor fails unexpectedly
//            $tw = $w; $th = $h;
//        }
        $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
        $key  = Thumbhash::convertHashToString($hash);

        // Store on Asset context or analysis bucket; keeping it simple here:
        $asset->context ??= [];
        $asset->context['thumbhash'] = $key;
    }

    public function maybeComputePaletteAndPhash(Asset $asset, string $preset, string $cachedPath): void
    {
        $sourcePath = $cachedPath;
        $tempPath = null;
        if (preg_match('#^https?://#', $cachedPath) === 1) {
            $bytes = @file_get_contents($cachedPath);
            if ($bytes !== false) {
                $tempPath = tempnam(sys_get_temp_dir(), 'asset_preview_');
                if ($tempPath !== false) {
                    file_put_contents($tempPath, $bytes);
                    $sourcePath = $tempPath;
                }
            }
        }

        try {
            $palette   = Palette::fromFilename($sourcePath);
            $extractor = new ColorExtractor($palette);
            $colors    = $extractor->extract(5); // array of ints (0xRRGGBB)
            $asset->context ??= [];
            $asset->context['colors'] = $colors;

            // Richer analysis (bucketed hues, coverage, etc.)
            $analysis = $this->colorAnalysisService->analyze($sourcePath, top: 5, hueBuckets: 36);
            $asset->context['color_analysis'] = $analysis;
        } catch (\Throwable $e) {
            // Non-fatal
        }

        try {
            $hasher = new ImageHash(new PerceptualHash()); // 64-bit pHash
            $hash   = $hasher->hash($sourcePath);
            $asset->context ??= [];
            $asset->context['phash'] = (string) $hash; // hex string
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if ($tempPath && is_file($tempPath)) {
            @unlink($tempPath);
        }
    }
}
