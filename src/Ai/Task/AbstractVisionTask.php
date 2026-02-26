<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Entity\Asset;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Twig\Environment as TwigEnvironment;

/**
 * Base class for tasks that send an image URL to a vision-capable LLM agent.
 *
 * Prompts live in Twig templates at:
 *   templates/ai/prompt/{task_value}/system.html.twig
 *   templates/ai/prompt/{task_value}/user.html.twig
 *
 * Subclasses override promptContext() to provide template variables.
 * They no longer need systemPrompt() / userPrompt() PHP methods.
 *
 * Template override: drop a file at the same path in your app's templates/
 * directory to override any prompt without touching PHP.
 */
abstract class AbstractVisionTask implements AssetAiTaskInterface
{
    public function __construct(
        protected readonly AgentInterface $agent,
        protected readonly TwigEnvironment $twig,
    ) {
    }

    // ── Subclass API ─────────────────────────────────────────────────────────

    /**
     * Variables passed to both system.html.twig and user.html.twig.
     *
     * Subclasses should override this instead of systemPrompt/userPrompt.
     * Common keys: ocr_text, type, metadata, asset.
     *
     * @return array<string, mixed>
     */
    protected function promptContext(Asset $asset, array $priorResults): array
    {
        return [
            'asset'        => $asset,
            'prior'        => $priorResults,
            'ocr_text'     => $this->ocrText($priorResults),
            'type'         => $this->classifiedType($priorResults),
            'metadata'     => $priorResults[AssetAiTask::EXTRACT_METADATA->value] ?? [],
            'description'  => $priorResults[AssetAiTask::CONTEXT_DESCRIPTION->value]['description']
                ?? $priorResults[AssetAiTask::BASIC_DESCRIPTION->value]['description']
                ?? null,
            'title'        => $priorResults[AssetAiTask::GENERATE_TITLE->value]['title'] ?? null,
        ];
    }

    /**
     * The PHP class to use as the structured response_format.
     * Return null to get raw text instead of structured output.
     *
     * @return class-string|null
     */
    abstract protected function responseFormatClass(): ?string;

    // ── AssetAiTaskInterface ─────────────────────────────────────────────────

    public function supports(Asset $asset): bool
    {
        return $this->imageUrl($asset) !== null;
    }

    public function run(Asset $asset, array $priorResults = []): array
    {
        $imageUrl = $this->imageUrl($asset);
        if ($imageUrl === null) {
            throw new \RuntimeException(\sprintf(
                'Task %s cannot run on asset %s: no image URL available.',
                $this->getTask()->value,
                $asset->id,
            ));
        }

        $context = $this->promptContext($asset, $priorResults);
        $taskSlug = $this->getTask()->value;

        $systemPrompt = trim($this->twig->render("ai/prompt/{$taskSlug}/system.html.twig", $context));
        $userPrompt   = trim($this->twig->render("ai/prompt/{$taskSlug}/user.html.twig",   $context));

        $messages = new MessageBag(
            Message::forSystem($systemPrompt),
            Message::ofUser($userPrompt, new ImageUrl($imageUrl)),
        );

        $options = [];
        if ($fmtClass = $this->responseFormatClass()) {
            $options['response_format'] = $fmtClass;
        }

        $result = $this->agent->call($messages, $options);

        $content = $result->getContent();

        if (\is_object($content) && $content instanceof \JsonSerializable) {
            $data = $content->jsonSerialize();
        } elseif (\is_array($content)) {
            $data = $content;
        } else {
            $data = ['raw' => (string) $content];
        }

        // Store token usage for cost tracking.
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

    // ── getMeta ───────────────────────────────────────────────────────────────

    public function getMeta(): array
    {
        $agentName = null;
        try {
            $rc = new \ReflectionClass(static::class);
            foreach ($rc->getConstructor()?->getParameters() ?? [] as $param) {
                foreach ($param->getAttributes(\Symfony\Component\DependencyInjection\Attribute\Autowire::class) as $attr) {
                    $args = $attr->getArguments();
                    $svc  = $args['service'] ?? $args[0] ?? null;
                    if ($svc && str_starts_with((string) $svc, 'ai.agent.')) {
                        $agentName = str_replace('ai.agent.', '', $svc);
                        break 2;
                    }
                }
            }
        } catch (\Throwable) {
        }

        // Render the system prompt template with empty context for display.
        $taskSlug = $this->getTask()->value;
        $systemPrompt = '';
        try {
            $dummy = new Asset('https://example.com/dummy.jpg');
            $context = $this->promptContext($dummy, []);
            $systemPrompt = trim($this->twig->render("ai/prompt/{$taskSlug}/system.html.twig", $context));
        } catch (\Throwable) {
        }

        return [
            'agent'         => $agentName ?? 'unknown',
            'platform'      => 'openai',
            'model'         => null,
            'system_prompt' => $systemPrompt,
            'template'      => "ai/prompt/{$taskSlug}/system.html.twig",
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function imageUrl(Asset $asset): ?string
    {
        return $asset->smallUrl ?? $asset->archiveUrl ?? $asset->originalUrl ?? null;
    }

    protected function priorResult(AssetAiTask $task, array $priorResults): ?array
    {
        return $priorResults[$task->value] ?? null;
    }

    protected function ocrText(array $priorResults): ?string
    {
        $ocr = $this->priorResult(AssetAiTask::OCR, $priorResults)
            ?? $this->priorResult(AssetAiTask::OCR_MISTRAL, $priorResults);

        return $ocr['text'] ?? null;
    }

    protected function classifiedType(array $priorResults): ?string
    {
        return $this->priorResult(AssetAiTask::CLASSIFY, $priorResults)['type'] ?? null;
    }
}
