<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Asset;
use App\Service\ClaimSearchSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills Asset::$claimCaption/$claimProse/$claimSubjects/$claimType from the claims store
 * so AssetSearch's full-text index covers the AI title/description/keywords — see
 * migrations/Version20260703120000 and ClaimSearchSync for why these columns exist.
 *
 * Safe to re-run any time (e.g. after a batch of new claims lands) — it always overwrites
 * with the current manifested claims.
 */
#[AsCommand(
    name: 'app:asset:sync-search-claims',
    description: 'Denormalize claims (AI caption/description/keywords) onto Asset for full-text search',
)]
final class SyncAssetSearchClaimsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimSearchSync $sync,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Batch size')]
        int $batchSize = 200,
    ): int {
        $repo = $this->em->getRepository(Asset::class);
        $total = (int) $repo->createQueryBuilder('a')->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        if ($total === 0) {
            $io->success('No assets found.');
            return 0;
        }

        $io->progressStart($total);
        $offset = 0;
        $synced = 0;
        while (true) {
            $batch = $repo->createQueryBuilder('a')
                ->orderBy('a.id')
                ->setFirstResult($offset)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();
            if ($batch === []) {
                break;
            }

            $this->sync->sync($batch);
            $this->em->flush();
            $this->em->clear();

            $synced += \count($batch);
            $offset += $batchSize;
            $io->progressAdvance(\count($batch));
        }
        $io->progressFinish();

        $io->success(sprintf('Synced claim search columns for %d asset(s).', $synced));

        return 0;
    }
}
