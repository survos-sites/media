<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{client}/batch', methods: ['POST', 'GET'])]
final class BatchController implements LoggerAwareInterface
{
    use LoggerAwareTrait;
    public function __construct(
        private readonly AssetRegistry     $assetRegistry,
        private readonly AsyncQueueLocator $asyncQueueLocator,
    ) {
    }

    public function __invoke(string $client, Request $request): JsonResponse
    {
        if ($request->isMethod('GET')) {
            $urls       = [$request->query->get('url')];
            $contextMap = [];
            $callbackUrl = $request->query->get('callback_url');
            $payload = ['client' => $client, 'urls' => $urls];
        } else {
            $payload     = $request->toArray();
            $urls        = $payload['urls'] ?? [];
            // Per-URL source metadata hints: ['https://...jpg' => ['dcterms:title' => '...', 'rights' => '...']]
            $contextMap  = is_array($payload['context'] ?? null) ? $payload['context'] : [];
            $callbackUrl = $payload['callback_url'] ?? null;
            // sync=true: process download immediately in this request, skip async queue
            if (!empty($payload['sync'])) {
                $this->asyncQueueLocator->sync = true;
            }
        }
//        file_put_contents('/tmp/payload.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->logger->warning(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $media = [];
        $urls  = array_unique(array_filter($urls));

        $queue = [];
        foreach ($urls as $url) {
            $contextHints = is_array($contextMap[$url] ?? null) ? $contextMap[$url] : [];
            // Store callback URL in context so the workflow can fire it after analysis
            if ($callbackUrl) {
                $contextHints['callback_url'] = $callbackUrl;
            }
            $asset = $this->assetRegistry->ensureAsset($url, $client, contextHints: $contextHints);
            if (!in_array($client = $payload['client'], $asset->clients, true)) {
                $asset->clients[] = $client;
            }
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
}
