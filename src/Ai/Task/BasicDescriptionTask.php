<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\DescriptionResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

final class BasicDescriptionTask extends AbstractVisionTask
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
        return AssetAiTask::BASIC_DESCRIPTION;
    }

    protected function responseFormatClass(): ?string
    {
        return DescriptionResult::class;
    }
}
