<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AssetRegistry;
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

        foreach ($urls as $url) {
            $asset = $this->assetRegistry->ensureAsset($url, $client, true);
            $this->assetRegistry->dispatch($asset);
            $media[] = [
                'originalUrl' => $url,
                'mediaKey' => $asset->id,
                'status' => $asset->marking,
            ];
        }
        $this->assetRegistry->flush();

        return new JsonResponse(['media' => $media]);
    }
}
