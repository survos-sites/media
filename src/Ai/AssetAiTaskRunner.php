<?php
declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use App\Workflow\AssetFlow as WF;
use App\Workflow\AssetWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Thin facade for queueing/running Asset AI tasks.
 *
 * Task execution now lives in AssetWorkflow so workflow transitions and
 * messenger-driven chaining stay in one place.
 */
final class AssetAiTaskRunner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        #[Target(WF::WORKFLOW_NAME)]
        private readonly WorkflowInterface $assetWorkflow,
        private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly AssetWorkflow $assetTaskWorkflow,
    ) {
    }

    /**
     * Run the next pending task from aiQueue.
     */
    public function runNext(Asset $asset): ?string
    {
        $ran = $this->assetTaskWorkflow->runNextAiTask($asset, completeWhenQueueEmpty: true);
        $this->entityManager->flush();

        return $ran;
    }

    /**
     * Drain aiQueue synchronously.
     *
     * @return string[]
     */
    public function runAll(Asset $asset): array
    {
        $ran = [];
        while (!empty($asset->aiQueue) && !$asset->aiLocked) {
            $taskName = $this->assetTaskWorkflow->runNextAiTask($asset, completeWhenQueueEmpty: true);
            if ($taskName === null) {
                break;
            }
            $ran[] = $taskName;
        }

        $this->entityManager->flush();

        return $ran;
    }

    /**
     * Run one named task immediately by injecting it at queue head.
     */
    public function runNamed(Asset $asset, string $taskName): ?string
    {
        $asset->aiQueue = [$taskName, ...$asset->aiQueue];
        return $this->runNext($asset);
    }

    /**
     * @param string[]|AssetAiTask[] $tasks
     */
    public function enqueue(Asset $asset, array $tasks): void
    {
        $names = array_map(
            static fn ($task): string => $task instanceof AssetAiTask ? $task->value : (string) $task,
            $tasks,
        );

        $asset->aiQueue = array_values(array_unique(array_merge($asset->aiQueue, $names)));
        $this->entityManager->flush();

        if ($this->assetWorkflow->can($asset, WF::TRANSITION_QUEUE_AI)) {
            $this->dispatchTransition($asset, WF::TRANSITION_QUEUE_AI);
        } elseif ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_TASK)) {
            $this->dispatchTransition($asset, WF::TRANSITION_AI_TASK);
        }
    }

    private function dispatchTransition(Asset $asset, string $transition): void
    {
        $message = new TransitionMessage($asset->id, Asset::class, $transition, WF::WORKFLOW_NAME);
        $this->messageBus->dispatch($message, $this->asyncQueueLocator->stamps($message));
    }
}
