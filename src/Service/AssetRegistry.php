<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Repository\AssetPathRepository;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AssetRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly AssetPathRepository $assetPathRepository,
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

//        // Determine 3-hex shard from binary id, not longer relevant, but was needer for LIIP.  We might want for archive storage, though.
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

        $flush && $this->entityManager->flush();

        return $asset;
    }

    public function s3Url(Asset $asset)
    {
        return sprintf("%s/%s/%s", $this->s3Endpoint, $this->s3Bucket, $asset->storageKey);

    }
}
