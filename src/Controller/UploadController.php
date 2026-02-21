<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AssetRegistry;
use App\Workflow\AssetWorkflow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{client}/upload', methods: ['POST'])]
final class UploadController
{
    public function __construct(
        private readonly AssetRegistry $assetRegistry,
        private readonly AssetWorkflow $assetWorkflow,
    ) {
    }

    public function __invoke(string $client, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Missing uploaded file under form field "file".');
        }

        $localPath = $file->getRealPath();
        if (!is_string($localPath) || $localPath === '' || !is_file($localPath)) {
            throw new BadRequestHttpException('Uploaded file is not available on local disk.');
        }

        $sha256 = hash_file('sha256', $localPath);
        $originalUrl = sprintf('upload://sha256/%s', $sha256);

        $asset = $this->assetRegistry->ensureAsset($originalUrl, $client);
        if (!in_array($client, $asset->clients, true)) {
            $asset->clients[] = $client;
        }

        if ($asset->storageKey === null) {
            $asset->context ??= [];
            $asset->context['upload'] = [
                'clientOriginalName' => $file->getClientOriginalName(),
                'clientMimeType' => $file->getClientMimeType(),
            ];

            $this->assetWorkflow->ingestLocalFile($asset, $localPath);
        } else {
            $this->assetRegistry->flush();
        }

        return new JsonResponse([
            'id' => $asset->id,
            'marking' => $asset->marking,
            'storageKey' => $asset->storageKey,
            'archiveUrl' => $asset->archiveUrl,
            'smallUrl' => $asset->smallUrl,
            'contentHash' => $asset->contentHash,
            'sha256' => $asset->context['sha256'] ?? $sha256,
        ]);
    }
}
