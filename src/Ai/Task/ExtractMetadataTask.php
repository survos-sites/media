<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\MetadataResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

final class ExtractMetadataTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
        TwigEnvironment $twig,
    ) {
        parent::__construct($agent, $twig);
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::EXTRACT_METADATA;
    }

    protected function responseFormatClass(): ?string
    {
        return MetadataResult::class;
    }
}
