<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\OcrResult;
use App\Entity\Asset;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Standard vision-model OCR.
 *
 * Uses gpt-4o (vision) via structured output to extract text with basic
 * block-level layout.  For more sophisticated layout analysis, use OcrMistralTask.
 */
final class OcrTask implements AssetAiTaskInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.ocr')]
        private readonly AgentInterface $agent,
    ) {
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::OCR;
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'ocr',
            'platform'      => 'openai',
            'model'         => 'gpt-4o',
            'system_prompt' => 'You are an expert OCR engine. Your sole job is to extract every character of text visible '
                . 'in the image as accurately as possible, preserving line breaks and paragraph structure. '
                . 'Return structured JSON only — no commentary.',
        ];
    }

    public function supports(Asset $asset): bool
    {
        return ($asset->smallUrl ?? $asset->archiveUrl ?? $asset->originalUrl) !== null;
    }

    public function run(Asset $asset, array $priorResults = []): array
    {
        $imageUrl = $asset->smallUrl ?? $asset->archiveUrl ?? $asset->originalUrl;

        $messages = new MessageBag(
            Message::forSystem(
                'You are an expert OCR engine. Your sole job is to extract every character of text visible '
                . 'in the image as accurately as possible, preserving line breaks and paragraph structure. '
                . 'Return structured JSON only — no commentary.'
            ),
            Message::ofUser(
                'Please extract all text from this image. '
                . 'Identify the language, estimate your confidence, and divide the text into logical blocks '
                . '(headings, paragraphs, captions, tables, etc.).',
                new ImageUrl($imageUrl),
            ),
        );

        $result = $this->agent->call($messages, [
            'response_format' => OcrResult::class,
        ]);

        $content = $result->getContent();

        if ($content instanceof OcrResult) {
            $data = $content->jsonSerialize();
        } elseif ($content instanceof \JsonSerializable) {
            $data = $content->jsonSerialize();
        } elseif (\is_array($content)) {
            $data = $content;
        } else {
            $data = ['text' => (string) $content, 'language' => null, 'confidence' => 'low', 'blocks' => []];
        }

        $tokenUsage = $result->getMetadata()->get('token_usage');
        if ($tokenUsage !== null) {
            $data['_tokens'] = [
                'prompt'     => $tokenUsage->getPromptTokens(),
                'completion' => $tokenUsage->getCompletionTokens(),
                'total'      => $tokenUsage->getTotalTokens(),
                'cached'     => $tokenUsage->getCachedTokens(),
            ];
        }

        return $data;
    }
}
