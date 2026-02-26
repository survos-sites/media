<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Entity\Asset;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Extract the structural layout of a document page: columns, tables, headings,
 * figure captions, and their bounding-box positions.
 *
 * Strategy (in order of preference):
 *   1. If OCR_MISTRAL has already run and its raw_response contains `pages`,
 *      parse the blocks directly — zero extra API cost.
 *   2. Otherwise call the Mistral OCR API fresh (same as OcrMistralTask).
 *
 * The result is a structured map of layout regions that downstream tasks
 * (e.g. EXTRACT_METADATA, SUMMARIZE) can use to understand page structure.
 */
final class LayoutTask implements AssetAiTaskInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(MISTRAL_API_KEY)%')]
        private readonly string $mistralApiKey,
    ) {
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::LAYOUT;
    }

    public function supports(Asset $asset): bool
    {
        $url = $asset->archiveUrl ?? $asset->originalUrl ?? null;
        if ($url === null) {
            return false;
        }
        $mime = $asset->mime ?? '';
        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'mistral-ocr-latest',
            'platform'      => 'mistral (direct HTTP)',
            'model'         => 'mistral-ocr-latest',
            'system_prompt' => 'Reuses OCR_MISTRAL raw_response if already run (zero API cost). '
                . 'Otherwise calls mistral-ocr-latest. Parses markdown blocks into typed layout regions: '
                . 'heading_1/2/3, paragraph, table, list, figure, blockquote.',
        ];
    }

    public function run(Asset $asset, array $priorResults = []): array
    {
        // ── 1. Reuse existing OCR_MISTRAL raw response if available ──────────
        // raw_response is stripped from $priorResults to avoid bloating prompts,
        // so we read it directly from the asset's aiCompleted entries.
        $mistralRaw = null;
        foreach ($asset->aiCompleted as $entry) {
            if (($entry['task'] ?? null) === AssetAiTask::OCR_MISTRAL->value) {
                $mistralRaw = $entry['result']['raw_response'] ?? null;
                break;
            }
        }

        if ($mistralRaw === null) {
            // ── 2. Fresh Mistral OCR call ─────────────────────────────────────
            $url = $asset->archiveUrl ?? $asset->originalUrl
                ?? throw new \RuntimeException('No URL for LayoutTask on asset ' . $asset->id);

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
            $mistralRaw = $response->toArray();
        }

        $pages = $mistralRaw['pages'] ?? [];

        // ── Parse layout regions from each page ───────────────────────────────
        $regions = [];
        foreach ($pages as $pageIndex => $page) {
            $pageRegions = $this->parsePageRegions($page, $pageIndex);
            $regions = array_merge($regions, $pageRegions);
        }

        return [
            'page_count' => count($pages),
            'regions'    => $regions,
            'summary'    => $this->summariseLayout($regions),
        ];
    }

    /**
     * Parse a single Mistral OCR page into typed layout regions.
     *
     * Mistral returns markdown per page. We infer region types from markdown
     * heading syntax, table pipes, and paragraph breaks. Bounding-box data
     * (bbox array) is used when present.
     *
     * @return array<int, array{type: string, page: int, text: string, bbox: array|null}>
     */
    private function parsePageRegions(array $page, int $pageIndex): array
    {
        $markdown = $page['markdown'] ?? '';
        $regions  = [];

        // Split into blocks on blank lines
        $blocks = preg_split('/\n{2,}/', trim($markdown)) ?: [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $type = match (true) {
                str_starts_with($block, '# ')   => 'heading_1',
                str_starts_with($block, '## ')  => 'heading_2',
                str_starts_with($block, '### ') => 'heading_3',
                str_contains($block, '|')       => 'table',
                str_starts_with($block, '> ')   => 'blockquote',
                str_starts_with($block, '- ') || str_starts_with($block, '* ') => 'list',
                preg_match('/^\d+\. /', $block) === 1 => 'ordered_list',
                str_starts_with($block, '![')   => 'figure',
                default                          => 'paragraph',
            };

            $regions[] = [
                'type' => $type,
                'page' => $pageIndex,
                'text' => $block,
                'bbox' => $page['bbox'] ?? null,
            ];
        }

        return $regions;
    }

    /**
     * Produce a compact human-readable summary of the layout structure.
     */
    private function summariseLayout(array $regions): string
    {
        if (empty($regions)) {
            return 'No layout regions detected.';
        }

        $counts = array_count_values(array_column($regions, 'type'));
        arsort($counts);

        $parts = [];
        foreach ($counts as $type => $count) {
            $parts[] = "{$count} {$type}" . ($count > 1 ? 's' : '');
        }

        return implode(', ', $parts) . '.';
    }
}
