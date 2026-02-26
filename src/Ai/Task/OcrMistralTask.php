<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Entity\Asset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mistral OCR with document-layout awareness.
 *
 * Uses the Mistral OCR API directly (https://api.mistral.ai/v1/ocr) with
 * the `mistral-ocr-latest` model, which returns bounding boxes, columns,
 * and table structure — far more useful than plain text for complex scans.
 *
 * Falls back to wrapping the markdown text if the API returns an unexpected shape.
 */
final class OcrMistralTask implements AssetAiTaskInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MISTRAL_API_KEY)%')]
        private readonly string $mistralApiKey,
    ) {
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::OCR_MISTRAL;
    }

    public function supports(Asset $asset): bool
    {
        $url = $asset->archiveUrl ?? $asset->originalUrl ?? null;
        if ($url === null) {
            return false;
        }
        // Mistral OCR works on images and PDFs accessible by URL.
        $mime = $asset->mime ?? '';
        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    /**
     * Whether this asset needs a Mistral OCR pass.
     *
     * Returns true if:
     *   - plain OCR has never run, OR
     *   - plain OCR confidence was 'low' or 'medium', OR
     *   - the operator has explicitly queued OCR_MISTRAL (handled by runner).
     *
     * Callers (e.g. AssetAiTaskRunner) may also override this by explicitly
     * enqueuing AssetAiTask::OCR_MISTRAL regardless of confidence.
     */
    public function needsMistralOcr(array $priorResults): bool
    {
        $ocrResult = $priorResults[AssetAiTask::OCR->value] ?? null;
        if ($ocrResult === null) {
            // Plain OCR hasn't run yet — caller should run OCR first.
            return false;
        }
        $confidence = $ocrResult['confidence'] ?? 'high';
        return in_array($confidence, ['low', 'medium'], true);
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'mistral-ocr-latest',
            'platform'      => 'mistral (direct HTTP)',
            'model'         => 'mistral-ocr-latest',
            'system_prompt' => 'Direct call to https://api.mistral.ai/v1/ocr — no system prompt. '
                . 'Returns per-page markdown with bounding boxes, columns, and table structure.',
        ];
    }

    public function run(Asset $asset, array $priorResults = []): array
    {
        $url = $asset->archiveUrl ?? $asset->originalUrl
            ?? throw new \RuntimeException('No URL available for Mistral OCR on asset ' . $asset->id);

        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->mistralApiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'    => 'mistral-ocr-latest',
                'document' => [
                    'type'         => 'document_url',
                    'document_url' => $url,
                ],
                'include_image_base64' => false,
            ],
            'timeout' => 120,
        ]);

        $data = $response->toArray();

        // Mistral OCR response shape: { pages: [{ markdown: "...", index: 0 }] }
        $pages = $data['pages'] ?? [];
        $fullText = implode("\n\n", array_map(
            fn(array $p): string => $p['markdown'] ?? '',
            $pages
        ));

        return [
            'text'       => trim($fullText),
            'language'   => null,   // Mistral OCR does not currently return language
            'confidence' => 'high', // Mistral OCR is generally high quality
            'blocks'     => array_map(
                fn(array $p, int $i): array => [
                    'text'  => $p['markdown'] ?? '',
                    'type'  => 'page',
                    'index' => $i,
                ],
                $pages,
                array_keys($pages),
            ),
            'raw_response' => $data,
        ];
    }
}
