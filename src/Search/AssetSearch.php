<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Asset;
use Doctrine\ORM\QueryBuilder;
use Mezcalito\UxSearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Mezcalito\UxSearchBundle\Search\AbstractSearch;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RangeInput;

#[AsSearch(Asset::class, name: 'asset', adapter: 'default')]
final class AssetSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        $this
            ->enableUrlRewriting()
            ->setAdapterParameters([
                DoctrineAdapter::SEARCH_FIELDS => [
                    'a.id',
                    'a.originalUrl',
                    'a.contentHash',
                    'a.localOcrText',
                    'a.localOcrPrimaryType',
                    'a.localOcrProvider',
                    'a.storageKey',
                    'a.aiDocumentType',
                    'a.parentKey',
                ],
                DoctrineAdapter::QUERY_BUILDER_ALIAS => 'a',
                DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $qb): void {},
                DoctrineAdapter::MAX_FACET_VALUES_PARAM => 25,
            ])
            ->addFacet('marking', 'State')
            ->addFacet('mime', 'MIME Type')
            ->addFacet('ext', 'Extension')
            ->addFacet('aiDocumentType', 'Document Type')
            ->addFacet('localOcrPrimaryType', 'OCR Type')
            ->addFacet('localOcrProvider', 'OCR Provider')
            ->addFacet('size', 'Size', RangeInput::class)
            ->addFacet('width', 'Width', RangeInput::class)
            ->addFacet('height', 'Height', RangeInput::class)
            ->addAvailableSort('a.createdAt:desc', 'Newest')
            ->addAvailableSort('a.createdAt:asc', 'Oldest')
            ->addAvailableSort('a.size:desc', 'Largest')
            ->addAvailableSort('a.size:asc', 'Smallest')
            ->addAvailableSort('a.width:desc', 'Widest')
            ->addAvailableSort('a.height:desc', 'Tallest')
            ->setAvailableHitsPerPage([24, 48, 96]);
    }
}
