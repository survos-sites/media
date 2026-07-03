<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\ClaimMetaResolver;
use Survos\ClaimsBundle\Service\ClaimReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Surfaces AI claims (the central claims store) in templates — notably the search hit card,
 * where photos have no title/denseSummary on the Asset itself but DO have observe/analysis
 * claims (ai:caption, ai:observationProse, dcterms:subject) keyed by the asset id.
 *
 * Claims live in a separate store and are NOT hydrated onto the Asset entity, so we read them
 * here. {@see asset_claims_prime} batch-loads every visible hit in one query (call it once at the
 * top of the Hits loop) so the per-card {@see assetMeta} lookups are cache hits, not N+1 queries.
 *
 * The predicate-priority resolution itself lives in {@see ClaimMetaResolver}, shared with
 * {@see \App\Service\ClaimSearchSync} which denormalizes the same fields onto Asset's FTS
 * columns — so what a card displays and what search can find never drift apart.
 */
final class AssetClaimsExtension extends AbstractExtension
{
    /** @var array<string, array<string, list<mixed>>> assetId => predicate => list of values (newest first) */
    private array $cache = [];

    public function __construct(
        private readonly ClaimReader $claimReader,
        private readonly ClaimMetaResolver $resolver,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset_claims_prime', [$this, 'prime']),
            new TwigFunction('asset_meta', [$this, 'assetMeta']),
        ];
    }

    /**
     * Batch-load manifested claims for many assets in a single query. Safe to call with a mix
     * of ids (already-primed ones are simply refreshed). No-op when the claims store is offline.
     *
     * @param iterable<mixed> $assetIds
     */
    public function prime(iterable $assetIds): void
    {
        if (!$this->claimReader->isAvailable()) {
            return;
        }

        $ids = [];
        foreach ($assetIds as $id) {
            if (is_string($id) && $id !== '' && !isset($this->cache[$id])) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return;
        }

        // Seed every requested id so a subject with zero claims is still "primed" (no re-query).
        foreach ($ids as $id) {
            $this->cache[$id] = [];
        }

        foreach ($this->claimReader->forSubjects(array_values($ids)) as $row) {
            $subjectId = $row['subject_id'] ?? null;
            $predicate = $row['predicate'] ?? null;
            if (!is_string($subjectId) || !is_string($predicate)) {
                continue;
            }
            // forSubjects() is ordered created_at DESC, so values land newest-first.
            $this->cache[$subjectId][$predicate][] = $row['value'] ?? null;
        }
    }

    /**
     * A small, display-ready metadata summary for one asset, assembled from its claims:
     *  caption  — best short title (ai:caption → observe:caption → dcterms:title)
     *  prose    — best descriptive caption (ai:observationProse → observe:description → ai:denseSummary)
     *  subjects — every dcterms:subject / observe:tag (places, topics, years all live here)
     *  type     — dcterms:type / observe:classification (e.g. "photograph")
     *  year     — 4-digit year mined from caption/dense summary/subjects ("exif-like")
     *
     * @return array{caption: ?string, prose: ?string, subjects: list<string>, type: ?string, year: ?string}
     */
    public function assetMeta(string $assetId): array
    {
        return $this->resolver->resolve($this->claimsFor($assetId));
    }

    /** @return array<string, list<mixed>> */
    private function claimsFor(string $assetId): array
    {
        if (!isset($this->cache[$assetId])) {
            $this->prime([$assetId]);
        }

        return $this->cache[$assetId] ?? [];
    }
}
