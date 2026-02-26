<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\SummaryResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

final class SummarizeTask extends AbstractVisionTask
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
        return AssetAiTask::SUMMARIZE;
    }

    protected function responseFormatClass(): ?string
    {
        return SummaryResult::class;
    }
}
