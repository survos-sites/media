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

    /**
     * Batch lookup — one query instead of one findOneBy() round trip per record key.
     *
     * @param string[] $recordKeys
     * @return array<string, MediaRecord> keyed by recordKey
     */
    public function findByRecordKeys(array $recordKeys): array
    {
        $recordKeys = array_values(array_unique($recordKeys));
        if ($recordKeys === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->where('r.recordKey IN (:keys)')
            ->setParameter('keys', $recordKeys)
            ->getQuery()
            ->getResult();

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row->recordKey] = $row;
        }

        return $byKey;
    }
}
