<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Entity\Asset;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Translate the text content of a document to English.
 *
 * Requires OCR or transcription to have been completed first.
 * Uses the OCR text directly; does not re-scan the image.
 * Useful for foreign-language material (e.g. Soviet Life magazine articles,
 * German military documents, French colonial records).
 */
final class TranslateTask implements AssetAiTaskInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        private readonly AgentInterface $agent,
    ) {
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::TRANSLATE;
    }

    public function supports(Asset $asset): bool
    {
        // Only useful when we already have OCR/transcription text to translate.
        return true;
    }

    public function getMeta(): array
    {
        return [
            'agent'         => 'metadata',
            'platform'      => 'openai',
            'model'         => 'gpt-4o-mini',
            'system_prompt' => 'You are a professional translator specialising in historical documents. '
                . 'Translate the provided text to English, preserving proper nouns, place names, and titles. '
                . 'Note the source language detected. If the text is already in English, return it unchanged.',
        ];
    }

    public function run(Asset $asset, array $priorResults = []): array
    {
        // Prefer Mistral OCR, then standard OCR, then handwriting transcription.
        $sourceText = $priorResults[AssetAiTask::OCR_MISTRAL->value]['text']
            ?? $priorResults[AssetAiTask::OCR->value]['text']
            ?? $priorResults[AssetAiTask::TRANSCRIBE_HANDWRITING->value]['text']
            ?? null;

        if ($sourceText === null || trim($sourceText) === '') {
            return [
                'translated_text' => null,
                'source_language' => null,
                'skipped'         => true,
                'reason'          => 'No source text available for translation.',
            ];
        }

        $messages = new MessageBag(
            Message::forSystem(
                'You are a professional translator specialising in historical documents. '
                . 'Translate the provided text to English. '
                . 'Preserve proper nouns, place names, and titles in their original form with English explanation in brackets. '
                . 'Note the source language detected. '
                . 'If the text is already in English, return it unchanged and note source_language = "en". '
                . 'Return a JSON object with keys: translated_text (string), source_language (ISO 639-1 code), notes (string or null).'
            ),
            Message::ofUser(
                "Translate the following text to English:\n\n" . mb_substr($sourceText, 0, 4000)
            ),
        );

        $result = $this->agent->call($messages);
        $content = $result->getContent();

        if (\is_array($content)) {
            return $content;
        }

        // Try to parse JSON from raw text response
        $raw = (string) $content;
        $start = strpos($raw, '{');
        if ($start !== false) {
            $decoded = json_decode(substr($raw, $start), true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'translated_text' => $raw,
            'source_language' => null,
            'notes'           => null,
        ];
    }
}
