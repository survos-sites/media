<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Repository\AssetPathRepository;
use App\Repository\AssetRepository;
use App\Workflow\AssetFlow;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Workflow\WorkflowInterface;

final class AssetRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly AssetPathRepository $assetPathRepository,
        private AsyncQueueLocator $asyncQueueLocator,
        #[Target(AssetFlow::WORKFLOW_NAME)] private WorkflowInterface $assetWorkflow,
        private MessageBusInterface $messageBus,
        #[Autowire('%env(S3_ENDPOINT)%')] private readonly string $s3Endpoint,
        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')] private readonly string $s3Bucket,

        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')]
        private readonly string        $archiveBucket,
        #[Autowire('%survos_media.imgproxy_base_url%')]
        private readonly string        $imgproxyBaseUrl,
        #[Autowire('%survos_media.imgproxy.key%')]
        private readonly string        $imgproxyKey,
        #[Autowire('%survos_media.imgproxy.salt%')]
        private readonly string        $imgproxySalt,
        private readonly MediaUrlGenerator $mediaUrlGenerator,

    ) {
    }

    /**
     * @param array<string,mixed> $contextHints Optional metadata from the caller (folder path, dates, etc.)
     */
    public function ensureAsset(string $originalUrl, ?string $client, bool $flush = false, array $contextHints = []): Asset
    {
        if (!$asset = $this->assetRepository->findOneByUrl($originalUrl)) {
            $asset = new Asset($originalUrl);
            $this->entityManager->persist($asset);
        }

        // Track which clients submitted this URL
        if ($client !== null && !in_array($client, $asset->clients, true)) {
            $asset->clients[] = $client;
        }

        // Merge source metadata from the caller into sourceMeta (non-destructive).
        // IIIF manifest attachment is intentionally deferred to async workflow.
        if ($contextHints !== []) {
            $asset->sourceMeta ??= [];
            foreach ($contextHints as $key => $value) {
                if (!isset($asset->sourceMeta[$key])) {
                    $asset->sourceMeta[$key] = $value;
                }
            }
        }

        $flush && $this->flush();

        return $asset;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function dispatch(Asset $asset): void
    {
        // trigger download
        $nextTransition = AssetFlow::TRANSITION_FETCH_IIIF;
        if ($this->assetWorkflow->can($asset, $nextTransition))
        {
            // dispatch a download request
            $message = new TransitionMessage($asset->id,
                $asset::class,
                $nextTransition,
                AssetFlow::WORKFLOW_NAME);
            $stamps = $this->asyncQueueLocator->stamps($message);
            $this->messageBus->dispatch(
                $message,
                $stamps
            );
        }


    }

    public function s3Url(Asset $asset)
    {
        return sprintf("%s/%s/%s", $this->s3Endpoint, $this->s3Bucket, $asset->storageKey);
    }
    public function imgProxyUrl(Asset $asset, string $preset = MediaUrlGenerator::PRESET_SMALL): ?string
    {
        return $this->imgProxyDebug($asset, $preset)['url'];
    }

    /** @return array{url: ?string, source: string, source_url: ?string} */
    public function imgProxyDebug(Asset $asset, string $preset = MediaUrlGenerator::PRESET_SMALL): array
    {
        // if the asset has been stored on OUR s3, then use it, much faster.
        if ($asset->storageKey) {
            $source = $this->s3Url($asset);
            $sourceLabel = 's3_url';
        } elseif (is_string($asset->archiveUrl) && $asset->archiveUrl !== '') {
            $source = $asset->archiveUrl;
            $sourceLabel = 'archive_url';
        } else {
            $source = $asset->originalUrl;
            $sourceLabel = 'original_url';
        }

        $imgproxyUrl = $this->mediaUrlGenerator->resizeRemote($source, 0, 0, $preset);
        return [
            'url' => $imgproxyUrl,
            'source' => $sourceLabel,
            'source_url' => $source,
        ];

    }

    public function imgProxyUrlWithCrop(
        Asset $asset,
        ?array $crop,
        ?int $resizeW,
        ?int $resizeH,
        bool $bestFit = false,
        ?string $effect = null
    ): string {
        $options = [];

        if ($crop !== null) {
            [$x, $y, $w, $h] = $crop;
            $options[] = new \Mperonnet\ImgProxy\Options\Crop(
                $w,
                $h,
                \Mperonnet\ImgProxy\Options\Gravity::northWest((float) $x, (float) $y)
            );
        }

        if ($resizeW || $resizeH) {
            $options[] = new \Mperonnet\ImgProxy\Options\Resize($bestFit ? 'fit' : 'fill');
            $options[] = new \Mperonnet\ImgProxy\Options\Width($resizeW ?? 0);
            $options[] = new \Mperonnet\ImgProxy\Options\Height($resizeH ?? 0);
        }

        if ($effect === 'grayscale') {
            $options[] = new \Mperonnet\ImgProxy\Options\Monochrome();
        }

        $builder = \Mperonnet\ImgProxy\UrlBuilder::signed(
            $this->imgproxyKey,
            $this->imgproxySalt
        )->with(...$options);

        if ($asset->storageKey) {
            $source = $this->s3Url($asset);
        } elseif (is_string($asset->archiveUrl) && $asset->archiveUrl !== '') {
            $source = $asset->archiveUrl;
        } else {
            $source = $asset->originalUrl;
            $builder = $builder->usePlain();
        }

        $path = $builder->url($source, 'jpg');

        return rtrim($this->imgproxyBaseUrl, '/') . $path;
    }
}
