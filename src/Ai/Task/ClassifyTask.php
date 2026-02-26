<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\ClassifyResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

final class ClassifyTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.classify')]
        AgentInterface $agent,
        TwigEnvironment $twig,
    ) {
        parent::__construct($agent, $twig);
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::CLASSIFY;
    }

    protected function responseFormatClass(): ?string
    {
        return ClassifyResult::class;
    }
}
