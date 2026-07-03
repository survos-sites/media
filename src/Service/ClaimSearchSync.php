<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Survos\ClaimsBundle\Service\ClaimReader;

/**
 * Denormalizes claims onto Asset::$claimCaption/$claimProse/$claimSubjects/$claimType so
 * AssetSearch's Postgres tsvector can index them — claims live in a separate database
 * (see ClaimReader), so a GIN expression index on the `asset` table can't reach them directly.
 *
 * Called from {@see \App\Command\SyncAssetSearchClaimsCommand} (backfill) and from the claim
 * write paths (AssetAiExecutor, ApplyBatchResultsMessageHandler) so newly-observed assets stay
 * searchable without a separate manual step.
 */
final class ClaimSearchSync
{
    public function __construct(
        private readonly ClaimReader $claimReader,
        private readonly ClaimMetaResolver $resolver,
    ) {
    }

    /**
     * Refresh the claim_* columns on the given assets from their current manifested claims.
     * Does not flush — caller controls the transaction/batch boundary.
     *
     * @param iterable<Asset> $assets
     */
    public function sync(iterable $assets): void
    {
        $byId = [];
        foreach ($assets as $asset) {
            $byId[$asset->id] = $asset;
        }
        if ($byId === [] || !$this->claimReader->isAvailable()) {
            return;
        }

        $claimsBySubject = [];
        foreach ($this->claimReader->forSubjects(array_keys($byId)) as $row) {
            $subjectId = $row['subject_id'] ?? null;
            $predicate = $row['predicate'] ?? null;
            if (!is_string($subjectId) || !is_string($predicate) || !isset($byId[$subjectId])) {
                continue;
            }
            // forSubjects() is ordered created_at DESC, so values land newest-first per predicate.
            $claimsBySubject[$subjectId][$predicate][] = $row['value'] ?? null;
        }

        foreach ($byId as $id => $asset) {
            $meta = $this->resolver->resolve($claimsBySubject[$id] ?? []);
            $asset->claimCaption = $meta['caption'];
            $asset->claimProse = $meta['prose'];
            $asset->claimSubjects = $meta['subjects'] === [] ? null : $meta['subjects'];
            $asset->claimType = $meta['type'];
        }
    }
}
