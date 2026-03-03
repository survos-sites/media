<?php
declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use App\Workflow\AssetFlow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Doctrine-aware runner: picks the next task off an Asset's aiQueue, runs it
 * via the bundle's AiTaskInterface, records the result, and advances the workflow.
 *
 * Task classes are auto-discovered via the `ai_pipeline.task` service tag
 * (registered in SurvosAiPipelineBundle via autoconfiguration on AiTaskInterface).
 */
final class AssetAiTaskRunner
{
    /** @var array<string, AiTaskInterface>  keyed by task name (getTask()) */
    private array $tasks = [];

    /**
     * @param iterable<AiTaskInterface> $taskServices
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Target(WF::WORKFLOW_NAME)]
        private readonly WorkflowInterface $assetWorkflow,
        private readonly LoggerInterface $logger,
        iterable $taskServices = [],
    ) {
        foreach ($taskServices as $task) {
            $this->tasks[$task->getTask()] = $task;
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run the next pending task from the asset's aiQueue.
     * Returns the task name that was run, or null if the queue was empty / locked.
     */
    public function runNext(Asset $asset): ?string
    {
        if ($asset->aiLocked) {
            $this->logger->info('AssetAiTaskRunner: asset {id} is locked, skipping.', ['id' => $asset->id]);
            return null;
        }

        if (empty($asset->aiQueue)) {
            $this->finishPipeline($asset);
            return null;
        }

        $taskName = array_shift($asset->aiQueue);
        $handler  = $this->tasks[$taskName] ?? null;

        if ($handler === null) {
            $this->logger->warning(
                'AssetAiTaskRunner: unknown task "{task}" on asset {id}, skipping.',
                ['task' => $taskName, 'id' => $asset->id],
            );
            $this->recordCompleted($asset, $taskName, ['skipped' => true, 'reason' => 'task class not found']);
            $this->entityManager->flush();
            return $taskName;
        }

        // Build inputs from the asset
        $inputs = $this->buildInputs($asset);

        if (!$handler->supports($inputs, $asset->context ?? [])) {
            $this->logger->info(
                'AssetAiTaskRunner: task "{task}" does not support asset {id}, skipping.',
                ['task' => $taskName, 'id' => $asset->id],
            );
            $this->recordCompleted($asset, $taskName, ['skipped' => true, 'reason' => 'not supported for this asset']);
            $this->entityManager->flush();
            return $taskName;
        }

        $priorResults = $this->indexedPriorResults($asset);

        $this->logger->info(
            'AssetAiTaskRunner: running task "{task}" on asset {id}.',
            ['task' => $taskName, 'id' => $asset->id],
        );

        try {
            $result = $handler->run($inputs, $priorResults, $asset->context ?? []);
            $this->recordCompleted($asset, $taskName, $result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AssetAiTaskRunner: task "{task}" failed on asset {id}: {error}',
                ['task' => $taskName, 'id' => $asset->id, 'error' => $e->getMessage()],
            );
            $this->recordCompleted($asset, $taskName, ['error' => $e->getMessage(), 'failed' => true]);
        }

        if (empty($asset->aiQueue)) {
            $this->finishPipeline($asset);
        } else {
            if ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_TASK)) {
                $this->assetWorkflow->apply($asset, WF::TRANSITION_AI_TASK);
            }
        }

        $this->entityManager->flush();

        return $taskName;
    }

    /**
     * Drain the entire aiQueue synchronously.
     * Returns the list of task names that were run.
     */
    public function runAll(Asset $asset): array
    {
        $ran = [];
        while (!empty($asset->aiQueue) && !$asset->aiLocked) {
            $name = $this->runNext($asset);
            if ($name === null) {
                break;
            }
            $ran[] = $name;
        }
        return $ran;
    }

    /**
     * Populate aiQueue with the given task list and apply the queue_ai transition.
     *
     * @param string[]|AssetAiTask[] $tasks  Task names or AssetAiTask enum cases
     */
    public function enqueue(Asset $asset, array $tasks): void
    {
        $names = array_map(
            fn($t): string => $t instanceof AssetAiTask ? $t->value : (string) $t,
            $tasks,
        );

        $asset->aiQueue = array_merge($asset->aiQueue, $names);

        if ($this->assetWorkflow->can($asset, WF::TRANSITION_QUEUE_AI)) {
            $this->assetWorkflow->apply($asset, WF::TRANSITION_QUEUE_AI);
        }

        $this->entityManager->flush();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build the inputs array from an Asset for passing to AiTaskInterface::run().
     *
     * @return array<string, mixed>
     */
    private function buildInputs(Asset $asset): array
    {
        return array_filter([
            'image_url' => $asset->smallUrl ?? $asset->archiveUrl ?? $asset->originalUrl ?? null,
            'mime'      => $asset->mime ?? null,
        ], fn($v) => $v !== null);
    }

    private function recordCompleted(Asset $asset, string $taskName, array $result): void
    {
        $asset->aiCompleted[] = [
            'task'   => $taskName,
            'at'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'result' => $result,
        ];

        if ($taskName === AssetAiTask::CLASSIFY->value
            && empty($result['failed']) && empty($result['skipped'])
        ) {
            $asset->aiDocumentType = $result['type'] ?? null;
        }
    }

    private function finishPipeline(Asset $asset): void
    {
        if ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_DONE)) {
            $this->assetWorkflow->apply($asset, WF::TRANSITION_AI_DONE);
        }
    }

    /**
     * @return array<string, array>
     */
    private function indexedPriorResults(Asset $asset): array
    {
        $index = [];
        foreach ($asset->aiCompleted as $entry) {
            if (isset($entry['task'], $entry['result'])) {
                $index[$entry['task']] = $this->sanitisePriorResult($entry['result']);
            }
        }
        return $index;
    }

    private function sanitisePriorResult(array $result): array
    {
        unset($result['raw_response'], $result['blocks']);
        if (isset($result['text']) && strlen($result['text']) > 8000) {
            $result['text'] = mb_substr($result['text'], 0, 8000) . "\n[… truncated for context]";
        }
        return $result;
    }
}
