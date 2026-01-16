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
            $urls = [$request->query->get('url')];
        } else {
            $payload = $request->toArray();
            $urls = $payload['urls'] ?? [];
        }

        $media = [];
        $urls = array_unique($urls);

        $queue = [];
        foreach ($urls as $url) {
            $asset = $this->assetRegistry->ensureAsset($url, $client);
            if ($asset->marking === AssetFlow::PLACE_NEW) {
                $queue[$asset->originalUrl] = $asset;
            }
            $media[] = [
                'originalUrl' => $url,
                'mediaKey' => $asset->id,
                'status' => $asset->marking,
                'storageKey' => $asset->storageKey, // for client to derive
                's3Url' => $asset->archiveUrl,
                'smallUrl' => $asset->smallUrl,
                'dispatched' => key_exists($url, $queue) ? 'yes' : 'no',
            ];
        }
        $this->assetRegistry->flush();

        foreach ($queue as $url=>$asset) {
            $this->assetRegistry->dispatch($asset);
        }
        $this->assetRegistry->flush();

        return new JsonResponse(['media' => $media]);
    }
}
