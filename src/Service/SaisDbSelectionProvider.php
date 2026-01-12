<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;

// any value to keeping this???

final class SaisDbSelectionProvider # implements SelectionProviderInterface
{
    public function __construct(public readonly EntityManagerInterface $em) {}

    public function countSelections(?string $root = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Media::class, 'm');

        if ($root !== null) {
            $qb->andWhere('m.root = :root')->setParameter('root', $root);
        }

        // Adjust to your schema. Examples:
        //  - thumbs column is NULL or empty JSON
        //  - marking indicates work needed
        $qb->andWhere('m.thumbs IS NULL OR m.thumbs = :empty')
           ->setParameter('empty', '[]'); // change to '{}' or similar if your DB stores objects

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getSelectionIterator(?string $root = null, int $offset = 0, ?int $limit = null): iterable
    {
        $chunk = 1000;
        $remaining = $limit ?? PHP_INT_MAX;

        for ($first = $offset; $remaining > 0; $first += $chunk) {
            $take = (int) min($chunk, $remaining);

            $qb = $this->em->createQueryBuilder()
                ->select('m')
                ->from(Media::class, 'm')
                ->orderBy('m.id', 'ASC')
                ->setFirstResult($first)
                ->setMaxResults($take);

            if ($root !== null) {
                $qb->andWhere('m.root = :root')->setParameter('root', $root);
            }

            $qb->andWhere('m.thumbs IS NULL OR m.thumbs = :empty')
               ->setParameter('empty', '[]');

            foreach ($qb->getQuery()->toIterable() as $media) {
                \assert($media instanceof Media);
                // Prefer an explicit SAIS code if you have one, otherwise the DB id
                $saisCode = property_exists($media, 'code') && $media->code
                    ? (string) $media->code
                    : (string) $media->id;

                yield [
                    'saisCode' => $saisCode,
                    'id'       => (string) $media->id,
                ];
            }

            if ($limit !== null) {
                $remaining -= $take;
                if ($remaining <= 0) {
                    break;
                }
            }
        }
    }
}
