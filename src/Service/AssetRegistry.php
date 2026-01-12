<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Repository\AssetPathRepository;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final class AssetRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly AssetPathRepository $assetPathRepository,
    ) {
    }

    public function ensureAsset(string $originalUrl): Asset
    {
        $asset = new Asset($originalUrl);

        if ($existing = $this->assetRepository->find($asset->id)) {
            return $existing;
        }

        // Determine 3-hex shard from binary id
        $hex = bin2hex($asset->id);
        $shard = substr($hex, 0, 3);

        $assetPath = $this->assetPathRepository->find($shard);
        if (!$assetPath) {
            $assetPath = new AssetPath($shard);
            $this->entityManager->persist($assetPath);
        }

        $asset->localDir = $assetPath;
        $assetPath->files++;

        $this->entityManager->persist($asset);
        $this->entityManager->flush();

        return $asset;
    }
}
