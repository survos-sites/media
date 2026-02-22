<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{client}/batch', methods: ['POST', 'GET'])]
final class BatchController
{
    public function __construct(
        private readonly AssetRegistry $assetRegistry,
    ) {
    }

    public function __invoke(string $client, Request $request): JsonResponse
    {
        if ($request->isMethod('GET')) {
            $urls       = [$request->query->get('url')];
            $contextMap = [];
            $callbackUrl = $request->query->get('callback_url');
        } else {
            $payload     = $request->toArray();
            $urls        = $payload['urls'] ?? [];
            // Per-URL context hints: ['https://...jpg' => ['path' => 'Cabinet 1/Folder 2', 'tenant' => 'rhs']]
            $contextMap  = is_array($payload['context'] ?? null) ? $payload['context'] : [];
            $callbackUrl = $payload['callback_url'] ?? null;
        }

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
                'clients'     => $asset->clients,
                'dispatched'  => array_key_exists($url, $queue) ? 'yes' : 'no',
            ];
        }
        $this->assetRegistry->flush();

        foreach ($queue as $url => $asset) {
            $this->assetRegistry->dispatch($asset);
        }
        $this->assetRegistry->flush();

        return new JsonResponse(['media' => $media]);
    }
}
