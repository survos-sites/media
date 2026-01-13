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
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class AssetRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly AssetPathRepository $assetPathRepository,
        private AsyncQueueLocator $asyncQueueLocator,
        private MessageBusInterface $messageBus,
        #[Autowire('%env(S3_ENDPOINT)%')] private readonly string $s3Endpoint,
        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')] private readonly string $s3Bucket,
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

    public function dispatch(Asset $asset, bool $sync=false): void
    {
        // trigger download
        if ($asset->getMarking() === AssetFlow::PLACE_NEW) {
            // dispatch a download request
            $message = new TransitionMessage($asset->id,
                $asset::class,
                AssetFlow::TRANSITION_DOWNLOAD,
                AssetFlow::WORKFLOW_NAME);
            if ($sync) {
                $stamps[] = new TransportNamesStamp(['sync']);
            } else {
                $stamps = $this->asyncQueueLocator->stamps($message);
            }
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
}
