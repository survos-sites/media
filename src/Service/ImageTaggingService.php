<?php
declare(strict_types=1);

// although this could be moved to a bundle, we'll keep it here for now, since it's where the images are.
// we could put the description and tags in the archive, too. (Plus the originalURL)

namespace App\Service;

use OpenAI;
use OpenAI\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ImageTaggingService
{
    private Client $client;

    public function __construct(
        #[Autowire('%env(OPENAI_API_KEY)%')]
        private readonly string $openaiApiKey,
        private readonly LoggerInterface $logger,
    ) {
        // @todo: check that this class exists
        if (!class_exists(Client::class)) {
            $this->logger->critical("You must enable the OpenAI extension to use OpenAI.  composer req openai-php/client");
            return;
        }
        $this->client = OpenAI::client($this->openaiApiKey);
    }

    /**
     * Tag a single image by URL (http/https or data: URI).
     *
     * @param string $url      Image URL (http, https, or data:)
     * @param array{
     *   detail?: 'low'|'high',
     *   lang?: string|null,
     *   year?: string|null,
     *   location?: string|null,
     *   knownTagsCsv?: string|null,
     *   hint?: string|null,
     *   model?: string|null,
     *   temperature?: float|null,
     *   maxTokens?: int|null
     * } $ctx
     *
     * @return array{
     *   tags: string[],
     *   safety: string,
     *   description: string,
     *   time_period: string|null,
     *   confidence: string,
     *   _usage?: array<string,int|null>
     * }
     */
    public function tagImageUrl(string $url, array $ctx = []): array
    {
        $detail      = $ctx['detail'] ?? 'low';
        $lang        = $ctx['lang'] ?? 'en';
        $year        = $ctx['year'] ?? null;
        $location    = $ctx['location'] ?? null;
        $known       = $ctx['knownTagsCsv'] ?? null;
        $hint        = $ctx['hint'] ?? null;
        $model       = $ctx['model'] ?? 'gpt-4o-mini';
        $temperature = $ctx['temperature'] ?? 0.2;
        $maxTokens   = $ctx['maxTokens'] ?? 400;

        if (!\in_array($detail, ['low', 'high'], true)) {
            throw new \InvalidArgumentException('detail must be "low" or "high".');
        }

        // Compose prompt with context
        $prompt = $this->buildPrompt(
            lang: $lang,
            year: $year,
            location: $location,
            knownTagsCsv: $known,
            hint: $hint
        );

        try {
            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => $detail]],
                    ],
                ]],
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
            ]);

            $content = $response->choices[0]->message->content ?? '';
            $parsed = $this->extractJson($content) ?? json_decode($content, true);

            if (!\is_array($parsed)) {
                throw new \RuntimeException('Could not parse JSON from model response.');
            }

            $result = [
                'tags'        => array_values(array_unique(array_map('strval', $parsed['tags'] ?? []))),
                'safety'      => (string)($parsed['safety'] ?? 'unknown'),
                'description' => (string)($parsed['description'] ?? ''),
                'time_period' => $parsed['time_period'] ?? null,
                'confidence'  => (string)($parsed['confidence'] ?? 'low'),
            ];

            // Optional usage stats (if present in SDK)
            $usage = $response->usage ?? null;
            if ($usage) {
                $result['_usage'] = [
                    'input_tokens'  => $usage->promptTokens ?? null,
                    'output_tokens' => $usage->completionTokens ?? null,
                    'total_tokens'  => $usage->totalTokens ?? null,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Image tagging failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build a compact instruction with contextual hints (year, location, existing tags, etc.).
     */
    private function buildPrompt(
        ?string $lang,
        ?string $year,
        ?string $location,
        ?string $knownTagsCsv,
        ?string $hint,
    ): string {
        $lines = [];

        $lines[] = trim("
You are an expert visual archivist. Analyze the image and output ONLY valid JSON with this schema:

{
  \"tags\": [\"tag1\", \"tag2\", \"tag3\"],          // 8–20 concise, lower-case, deduped
  \"safety\": \"safe|questionable|unsafe\",          // conservative if nudity/violence/etc.
  \"description\": \"One sentence in " . ($lang ?: 'en') . "\",
  \"time_period\": \"YYYY or '1930s' or null\",
  \"confidence\": \"high|medium|low\"
}

Rules:
- Prefer concrete nouns (objects, clothing, setting) + activities + era/style.
- Avoid subjective/aesthetic adjectives; avoid commas inside individual tags.
- Do NOT include personal names unless clearly indicated (e.g., uniforms/regalia).
- Output must be valid JSON. No extra commentary.
        ");

        $ctx = [];
        if ($year)     { $ctx[] = "Year (approx): {$year}"; }
        if ($location) { $ctx[] = "Location (approx): {$location}"; }
        if ($knownTagsCsv) {
            $ctx[] = "Existing human tags (refine, dedupe, and extend): {$knownTagsCsv}";
        }
        if ($hint)     { $ctx[] = "Additional context: {$hint}"; }

        if ($ctx) {
            $lines[] = "Context:\n- " . implode("\n- ", $ctx);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Extract the first balanced JSON object from a free-form LLM response.
     */
    private function extractJson(string $content): ?array
    {
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $len = strlen($content);
        for ($i = $start; $i < $len; $i++) {
            $ch = $content[$i];
            if ($ch === '{') $depth++;
            if ($ch === '}') $depth--;
            if ($depth === 0) {
                $json = substr($content, $start, $i - $start + 1);
                $decoded = json_decode($json, true);
                if (\is_array($decoded)) {
                    return $decoded;
                }
                $next = strpos($content, '{', $i + 1);
                if ($next === false) {
                    return null;
                }
                $i = $next - 1; // continue from next brace
            }
        }
        return null;
    }
}
