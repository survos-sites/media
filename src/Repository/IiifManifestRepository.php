<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IiifManifest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IiifManifest>
 */
final class IiifManifestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IiifManifest::class);
    }

    public function findOneByManifestUrl(string $manifestUrl): ?IiifManifest
    {
        return $this->findOneBy(['manifestUrl' => $manifestUrl]);
    }
}
