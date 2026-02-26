<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\DescriptionResult;
use App\Entity\Asset;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

final class ContextDescriptionTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.description')]
        AgentInterface $agent,
        TwigEnvironment $twig,
    ) {
        parent::__construct($agent, $twig);
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::CONTEXT_DESCRIPTION;
    }

    protected function promptContext(Asset $asset, array $priorResults): array
    {
        return array_merge(parent::promptContext($asset, $priorResults), [
            'organisations' => $priorResults[AssetAiTask::EXTRACT_METADATA->value]['organisations'] ?? [],
            'collection_context' => $asset->context ?? null,
        ]);
    }

    protected function responseFormatClass(): ?string
    {
        return DescriptionResult::class;
    }
}
