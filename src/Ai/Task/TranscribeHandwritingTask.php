<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Ai\Result\OcrResult;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

final class TranscribeHandwritingTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.mistral_vision')]
        AgentInterface $agent,
        TwigEnvironment $twig,
    ) {
        parent::__construct($agent, $twig);
    }

    public function getTask(): AssetAiTask
    {
        return AssetAiTask::TRANSCRIBE_HANDWRITING;
    }

    protected function responseFormatClass(): ?string
    {
        return OcrResult::class;
    }
}
