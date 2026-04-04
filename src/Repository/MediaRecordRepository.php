<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MediaRecord>
 */
final class MediaRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaRecord::class);
    }

    public function findOneByRecordKey(string $recordKey): ?MediaRecord
    {
        return $this->findOneBy(['recordKey' => $recordKey]);
    }
}
