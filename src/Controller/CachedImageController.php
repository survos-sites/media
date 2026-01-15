<?php

declare(strict_types=1);

namespace App\Controller;


use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Survos\MediaBundle\Service\MediaKeyService;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;

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

    public function __construct(
        private readonly AssetRegistry $assetRegistry,
        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')]
        private readonly string        $archiveBucket,
        #[Autowire('%survos_media.imgproxy_base_url%')]
        private readonly string        $imgproxyBaseUrl,
        #[Autowire('%survos_media.imgproxy.key%')]
        private readonly string        $imgproxyKey,
        #[Autowire('%survos_media.imgproxy.salt%')]
        private readonly string        $imgproxySalt,
        private AsyncQueueLocator      $asyncQueueLocator,
        private MessageBusInterface    $messageBus, private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/media/{preset}/{encoded}', name: 'sais_cached_image', options: ['expose' => true])]
    public function renderImage(
        string $preset,
        string $encoded,
        #[MapQueryParameter] ?string $client = null,
        #[MapQueryParameter] ?bool $sync = null,
    ): Response
    {
        if (!isset(MediaUrlGenerator::PRESETS[$preset])) {
            throw new BadRequestHttpException('Unknown image preset: ' . $preset);
        }

        $source = MediaKeyService::stringFromEncoded($encoded);
        if ($source === false) {
            throw new BadRequestHttpException('Invalid base64 source.');
        }

        if ($sync) {
            $this->asyncQueueLocator->sync = true;
        }


        // Ensure asset is registered
        $asset = $this->assetRegistry->ensureAsset($source, $client, flush: true);
        // queue up download
        $this->assetRegistry->dispatch($asset);

        // if the asset has been stored on OUR s3, then use it, much faster.
        if ($asset->storageKey) {
            $source = $this->assetRegistry->s3Url($asset);
        }

        // Redirect to imgproxy for now (no byte streaming or caching yet)
        $presetDef = MediaUrlGenerator::PRESETS[$preset];
        [$width, $height] = $presetDef['size'];

        $builder = \Mperonnet\ImgProxy\UrlBuilder::signed(
            $this->imgproxyKey,
            $this->imgproxySalt
        )->with(
            new \Mperonnet\ImgProxy\Options\Resize($presetDef['resize']),
            new \Mperonnet\ImgProxy\Options\Width($width),
            new \Mperonnet\ImgProxy\Options\Height($height),
            new \Mperonnet\ImgProxy\Options\Quality($presetDef['quality']),
        );

        if (isset($presetDef['dpr'][0])) {
//            $builder = $builder->with(new \Mperonnet\ImgProxy\Options\Dpr($presetDef['dpr'][0]));
        }

        $path = $builder->url($source, $presetDef['format']);
        $imgproxyUrl = rtrim($this->imgproxyBaseUrl, '/') . $path;
        $this->logger->info("Redirecting with image: {$source}");

        $response = new \Symfony\Component\HttpFoundation\RedirectResponse($imgproxyUrl, 302);
        // Cache aggressively: imgproxy URLs are content-addressed
//        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        return $response;
    }
}
