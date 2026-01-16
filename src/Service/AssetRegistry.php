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

    ) {
    }

    public function ensureAsset(string $originalUrl, ?string $client, bool $flush=false): Asset
    {

        if (!$asset = $this->assetRepository->findOneByUrl($originalUrl)) {
            $asset = new Asset($originalUrl);
            $this->entityManager->persist($asset);
        }

//        // Determine 3-hex shard from binary id, not longer relevant, but was needed for LIIP.  We might want for archive storage, though.
//        $hex = bin2hex($asset->id);
//        $shard = substr($hex, 0, 3);
//
//        $assetPath = $this->assetPathRepository->find($shard);
//        if (!$assetPath) {
//            $assetPath = new AssetPath($shard);
//            $this->entityManager->persist($assetPath);
//        }
//        if (!in_array($client, $asset->clients)) {
//            $asset->clients[] = $client;
//        }

//        $asset->localDir = $assetPath;
//        $assetPath->files++;

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
        if ($this->assetWorkflow->can($asset, AssetFlow::TRANSITION_DOWNLOAD))
        {
            // dispatch a download request
            $message = new TransitionMessage($asset->id,
                $asset::class,
                AssetFlow::TRANSITION_DOWNLOAD,
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

        // if the asset has been stored on OUR s3, then use it, much faster.
        if ($asset->storageKey) {
            $source = $this->s3Url($asset);
        } else {
            $source = $asset->originalUrl;
            $builder = $builder->usePlain();
        }
        $path = $builder->url($source, $presetDef['format']);
//        $url = $builder->usePlain()->url($src);
// Example: /9SaGqJILqstFsWthdP/dpr:2/q:90/w:300/h:400/plain/http://example.com/image.jpg

        $imgproxyUrl = rtrim($this->imgproxyBaseUrl, '/') . $path;
        return $imgproxyUrl;

    }
}
