<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Task\AssetAiTaskInterface;
use App\Entity\Asset;
use App\Workflow\AssetFlow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Picks the next task off an Asset's aiQueue, runs it, records the result,
 * and advances the workflow transition.
 *
 * Usage:
 *   $runner->runNext($asset);        // run one task
 *   $runner->runAll($asset);         // drain the whole queue
 *
 * The runner is intentionally single-task-per-call so that async workers
 * can checkpoint between tasks and the operator can lock mid-pipeline.
 *
 * Task classes are auto-discovered via the `ai.task` service tag.
 * See services.yaml for the tag configuration.
 */
final class AssetAiTaskRunner
{
    /** @var array<string, AssetAiTaskInterface>  keyed by AssetAiTask::value */
    private array $tasks = [];

    /**
     * @param iterable<AssetAiTaskInterface> $taskServices
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Target(WF::WORKFLOW_NAME)]
        private readonly WorkflowInterface $assetWorkflow,
        private readonly LoggerInterface $logger,
        iterable $taskServices = [],
    ) {
        foreach ($taskServices as $task) {
            $this->tasks[$task->getTask()->value] = $task;
        }
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run the next pending task from the asset's aiQueue.
     *
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

        $task = $this->tasks[$taskName] ?? null;
        if ($task === null) {
            $this->logger->warning(
                'AssetAiTaskRunner: unknown task "{task}" on asset {id}, skipping.',
                ['task' => $taskName, 'id' => $asset->id],
            );
            // Still record as completed (skipped) so the queue drains
            $this->recordCompleted($asset, $taskName, ['skipped' => true, 'reason' => 'task class not found']);
            $this->entityManager->flush();
            return $taskName;
        }

        if (!$task->supports($asset)) {
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
            $result = $task->run($asset, $priorResults);
            $this->recordCompleted($asset, $taskName, $result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AssetAiTaskRunner: task "{task}" failed on asset {id}: {error}',
                ['task' => $taskName, 'id' => $asset->id, 'error' => $e->getMessage()],
            );
            $this->recordCompleted($asset, $taskName, [
                'error'   => $e->getMessage(),
                'failed'  => true,
            ]);
        }

        // Advance workflow: stay in ai_ready if more tasks remain, else finish.
        if (empty($asset->aiQueue)) {
            $this->finishPipeline($asset);
        } else {
            if ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_TASK)) {
                // Stays in ai_ready; the marking doesn't actually change but
                // the transition fires listeners / logs.
                $this->assetWorkflow->apply($asset, WF::TRANSITION_AI_TASK);
            }
        }

        $this->entityManager->flush();

        return $taskName;
    }

    /**
     * Drain the entire aiQueue synchronously (use with caution in web context).
     *
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
     * @param AssetAiTask[] $tasks
     */
    public function enqueue(Asset $asset, array $tasks): void
    {
        $asset->aiQueue = array_merge(
            $asset->aiQueue,
            array_map(fn(AssetAiTask $t): string => $t->value, $tasks),
        );

        if ($this->assetWorkflow->can($asset, WF::TRANSITION_QUEUE_AI)) {
            $this->assetWorkflow->apply($asset, WF::TRANSITION_QUEUE_AI);
        }

        $this->entityManager->flush();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function recordCompleted(Asset $asset, string $taskName, array $result): void
    {
        $asset->aiCompleted[] = [
            'task'   => $taskName,
            'at'     => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'result' => $result,
        ];

        // aiDocumentType is the one field kept as a real column (for SQL WHERE).
        if ($taskName === AssetAiTask::CLASSIFY->value
            && empty($result['failed']) && empty($result['skipped'])
        ) {
            $asset->aiDocumentType = $result['type'] ?? null;
        }

        // Everything else (title, description, OCR text, keywords, etc.) is
        // computed from aiCompleted by AssetNormalizer at serialisation time.
    }

    private function finishPipeline(Asset $asset): void
    {
        if ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_DONE)) {
            $this->assetWorkflow->apply($asset, WF::TRANSITION_AI_DONE);
        }
    }

    /**
     * Build an array of prior results keyed by task name for easy lookup.
     *
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

    /**
     * Strip large/irrelevant keys from a prior result before passing it to
     * downstream tasks via their prompts.
     *
     * - raw_response: full Mistral OCR API payload — can be several MB
     * - blocks: per-page block arrays — useful only to LayoutTask which reads
     *   raw_response directly; other tasks only need the text
     * - text: cap at 8 000 chars — enough context for any downstream prompt
     */
    private function sanitisePriorResult(array $result): array
    {
        unset($result['raw_response'], $result['blocks']);

        if (isset($result['text']) && strlen($result['text']) > 8000) {
            $result['text'] = mb_substr($result['text'], 0, 8000) . "\n[… truncated for context]";
        }

        return $result;
    }
}
