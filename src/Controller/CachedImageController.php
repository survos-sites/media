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
        'hero'  => 'resize:fit:1200:600',
    ];



    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?Stopwatch $stopwatch=null,
    ) {
    }

    #[Route('/sais/image/{preset}/{encoded}', name: 'sais_cached_image', options: ['expose' => true])]
    public function __invoke(string $preset, string $encoded): Response
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new BadRequestHttpException('Unknown image preset.');
        }

        $source = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($source === false) {
            throw new BadRequestHttpException('Invalid base64 source.');
        }

        $options = self::PRESETS[$preset];
        $cacheKey = sha1($source . '|' . $options);

        $cacheDir = $this->projectDir . '/public/cache';
        $cachePath = $cacheDir . '/' . $cacheKey;

        if (file_exists($cachePath)) {
            $response = new BinaryFileResponse($cachePath);
            $response->headers->set('X-Sais-Cache', 'hit');
            return $response;
        }

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('Failed to create cache directory.');
        }

        $imgproxyUrl = sprintf(
            '%s/%s/%s',
            'https://images.survos.com',
            $options,
            $encoded
        );

        $this->stopwatch->start('imgproxy_fetch');
        $imgResponse = $this->httpClient->request('GET', $imgproxyUrl);
        $content = $imgResponse->getContent();
        $event = $this->stopwatch->stop('imgproxy_fetch');

        $tmpPath = $cachePath . '.tmp';
        if (file_put_contents($tmpPath, $content) === false) {
            throw new RuntimeException('Failed writing cached image.');
        }

        if (!rename($tmpPath, $cachePath)) {
            throw new RuntimeException('Failed finalizing cached image.');
        }

        $response = new BinaryFileResponse($cachePath);
        $response->headers->set('X-Sais-Cache', 'miss');
        $response->headers->set('X-Sais-Fetch-Time', (string) $event->getDuration());
        $response->headers->set('X-Sais-Source', $imgproxyUrl);
        return $response;
    }
}
