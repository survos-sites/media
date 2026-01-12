<?php

declare(strict_types=1);

namespace App\Controller;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Stopwatch\Stopwatch;

use function base64_decode;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rename;
use function sha1;
use function sprintf;
use function strtr;

final class CachedImageController
{
    public const PRESETS = [
        'small' => 'resize:fit:150:75',
        'thumb' => 'resize:fill:200:200',
        'large' => 'resize:fill:800:800',
        'hero'  => 'resize:fit:1200:600',
    ];



    public function __construct(
        private readonly \App\Service\AssetRegistry $assetRegistry,
    ) {
    }

    #[Route('/media/{preset}/{encoded}', name: 'sais_cached_image', options: ['expose' => true])]
    public function __invoke(string $preset, string $encoded): Response
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new BadRequestHttpException('Unknown image preset.');
        }

        $source = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($source === false) {
            throw new BadRequestHttpException('Invalid base64 source.');
        }

        // Ensure asset is registered
        $asset = $this->assetRegistry->ensureAsset($source);

        // Redirect to imgproxy for now (no byte streaming or caching yet)
        $options = self::PRESETS[$preset];
        $imgproxyUrl = sprintf(
            '%s/%s/%s',
            'https://images.survos.com',
            $options,
            $encoded
        );

        return new \Symfony\Component\HttpFoundation\RedirectResponse($imgproxyUrl, 302);
    }
}
