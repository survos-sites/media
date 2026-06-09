<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Asset;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Mezcalito\UxSearchBundle\Search\AbstractSearch;

#[AsSearch(Asset::class, name: 'asset', adapter: 'bm25')]
final class AssetSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        // Weighted full-text vector. Must stay identical to the expression GIN index
        // idx_asset_fts (migrations/Version20260605000000) or the planner won't use it.
        // A=classification labels, B=detected objects, C=document type, D=OCR text.
        // TODO: add title/description (B/A) once those columns are populated.
        $vector =
            "(setweight(to_tsvector('english', coalesce(d.classification::text, '')), 'A') || "
            ."setweight(to_tsvector('english', coalesce(d.object_identifiers::text, '')), 'B') || "
            ."setweight(to_tsvector('english', coalesce(d.ai_document_type, '')), 'C') || "
            ."setweight(to_tsvector('english', coalesce(d.local_ocr_text, '')), 'D'))";

        $this
            ->enableUrlRewriting()
            ->setAdapterParameters([
                'table' => 'asset',
                'idColumn' => 'id',
                // Only the id is needed: HitEntityHydrator loads the Asset entity.
                'selectColumns' => ['id'],
                'matchExpression' => "{$vector} @@ websearch_to_tsquery('english', :bm25Query)",
                'scoreExpression' => "ts_rank({$vector}, websearch_to_tsquery('english', :bm25Query))",
                // camelCase facet property => physical column (alias d).
                'facetColumns' => [
                    'provider' => 'd.provider',
                    'dataset' => 'd.dataset',
                    'marking' => 'd.marking',
                    'mime' => 'd.mime',
                    'ext' => 'd.ext',
                    'aiDocumentType' => 'd.ai_document_type',
                    'localOcrPrimaryType' => 'd.local_ocr_primary_type',
                ],
                'sortColumns' => [
                    'a.createdAt' => 'd.created_at',
                    'a.size' => 'd.size',
                ],
                'maxFacetValues' => 25,
                // OCR-only matches (weight D) score < ~0.083; classification/object/
                // doc-type matches (A/B/C) score >= ~0.1. Drop the OCR-only noise
                // (e.g. 'school' matching random newspaper scans). Tune via the score
                // badge in the hit template.
                'scoreThreshold' => 0.1,
            ])
            ->addFacet('provider', 'Provider')
            ->addFacet('dataset', 'Dataset')
            ->addFacet('marking', 'State')
            ->addFacet('mime', 'MIME Type')
            ->addFacet('ext', 'Extension')
            ->addFacet('aiDocumentType', 'Document Type')
            ->addFacet('localOcrPrimaryType', 'OCR Type')
            ->addAvailableSort('a.createdAt:desc', 'Newest')
            ->addAvailableSort('a.createdAt:asc', 'Oldest')
            ->addAvailableSort('a.size:desc', 'Largest')
            ->addAvailableSort('a.size:asc', 'Smallest')
            ->setAvailableHitsPerPage([24, 48, 96]);
    }
}
