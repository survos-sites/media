<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\MediaRecord;
use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\MediaBundle\Dto\BatchPayloadDto;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class BatchController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly AssetRegistry     $assetRegistry,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly ClaimIngestor     $claimIngestor,
    ) {
    }

    /** POST: Symfony deserializes + validates the JSON body straight into the DTO. */
    #[Route('/{client}/batch', methods: ['POST'])]
    public function post(string $client, #[MapRequestPayload] BatchPayloadDto $payload): JsonResponse
    {
        return $this->handle($client, $payload);
    }

    /** GET: debug single-URL registration via ?url=…&callback_url=… */
    #[Route('/{client}/batch', methods: ['GET'])]
    public function get(string $client, Request $request): JsonResponse
    {
        $url = $request->query->get('url');

        return $this->handle($client, new BatchPayloadDto(
            client: $client,
            urls: $url !== null ? [$url] : [],
            callbackUrl: $request->query->get('callback_url'),
        ));
    }

    private function handle(string $client, BatchPayloadDto $payload): JsonResponse
    {
        $this->logger->warning(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // sync=true: process download immediately in this request, skip async queue
        if ($payload->sync) {
            $this->asyncQueueLocator->sync = true;
        }

        $media = [];
        $queue = [];

        foreach ($payload->cleanUrls() as $url) {
            $item         = $payload->itemFor($url);
            $contextHints = $item->toArray();
            // Store callback URL in context so the workflow can fire it after analysis
            if ($payload->callbackUrl) {
                $contextHints['callback_url'] = $payload->callbackUrl;
            }

            $asset = $this->assetRegistry->ensureAsset($url, $client, contextHints: $contextHints);
            if (!in_array($client, $asset->clients, true)) {
                $asset->clients[] = $client;
            }

            // Item-level source claims (title/date/place/keywords) belong to the
            // record, not the image. Ingest as @import claims keyed by the record
            // so they survive multi-image and so AI claims (own source) compare
            // against, never inherit, human metadata.
            $this->ingestSourceClaims($asset, $payload->claimsFor($url));
            if ($asset->marking === AssetFlow::PLACE_NEW) {
                $queue[$asset->originalUrl] = $asset;
            }
            $media[] = [
                'originalUrl' => $url,
                'mediaKey'    => $asset->id,
                'status'      => $asset->marking,
                'storageKey'  => $asset->storageKey,
                's3Url'       => $asset->archiveUrl,
                'smallUrl'    => $asset->smallUrl,
                'iiifManifestId' => $asset->iiifManifestEntity?->id,
                'iiifManifest'   => $asset->iiifManifestEntity?->manifestUrl ?? ($asset->sourceMeta['iiif_manifest'] ?? null),
                'iiifBase'       => $asset->iiifManifestEntity?->imageBase ?? ($asset->sourceMeta['iiif_base'] ?? null),
                'iiifThumb'      => $asset->iiifManifestEntity?->thumbnailUrl ?? ($asset->sourceMeta['iiif_thumbnail_url'] ?? null),
                'clients'     => $asset->clients,
                'dispatched'  => array_key_exists($url, $queue) ? 'yes' : 'no',
            ];
        }
        $this->assetRegistry->flush();

        // dispatch auto-download for the moment, let's focus on metadata
        foreach ($queue as $url => $asset) {
            $this->assetRegistry->dispatch($asset);
        }
        $this->assetRegistry->flush();

        return new JsonResponse(['media' => $media]);
    }

    /**
     * Ingest item-level source metadata as @import claims on the asset's MediaRecord.
     * No-op when there are no claims or no record — the record only exists when the
     * caller sent a grouping key (the item id as code/dcterms:identifier/media_record_key).
     *
     * @param list<array<string,mixed>> $claims list of ['predicate'=>string, 'value'=>mixed, 'confidence'?=>int, 'basis'?=>string]
     */
    private function ingestSourceClaims(Asset $asset, array $claims): void
    {
        if ($claims === [] || $asset->mediaRecord === null) {
            return;
        }

        $raw = [];
        foreach ($claims as $c) {
            if (!is_array($c) || !isset($c['predicate']) || !array_key_exists('value', $c)) {
                continue;
            }
            $raw[] = new RawClaim(
                (string) $c['predicate'],
                $c['value'],
                isset($c['confidence']) ? (int) $c['confidence'] : 100,
                isset($c['basis']) ? (string) $c['basis'] : null,
            );
        }
        if ($raw === []) {
            return;
        }

        $this->claimIngestor->record(
            scope: $asset->dataset,
            subjectType: MediaRecord::class,
            subjectId: $asset->mediaRecord->id,
            source: '@import',
            rawClaims: $raw,
        );
    }
}
