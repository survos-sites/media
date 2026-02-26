<?php

declare(strict_types=1);

namespace App\Ai\Task;

use App\Ai\AssetAiTask;
use App\Entity\Asset;

/**
 * Contract every AI task class must satisfy.
 *
 * A task receives an Asset plus the accumulated results of any previously
 * completed tasks (from Asset::$aiCompleted), runs one operation against
 * an LLM/vision model, and returns a JSON-serializable result array.
 *
 * The AssetAiTaskRunner picks the task off the front of aiQueue, calls
 * run(), and appends the result to aiCompleted.
 */
interface AssetAiTaskInterface
{
    /** The enum case this class handles. */
    public function getTask(): AssetAiTask;

    /**
     * Execute the task and return a serializable result.
     *
     * @param Asset $asset          The asset to process.
     * @param array $priorResults   Keyed by task name → result array, from aiCompleted.
     *
     * @return array  Will be stored verbatim in aiCompleted[]['result'].
     *
     * @throws \Throwable on unrecoverable failures.
     */
    public function run(Asset $asset, array $priorResults = []): array;

    /**
     * Whether this task can be applied to the given asset.
     * Default implementation checks mime type; tasks may override.
     */
    public function supports(Asset $asset): bool;

    /**
     * Human-readable metadata about this task for display in the UI.
     *
     * Returns:
     *   agent   - agent/service name (e.g. "ai.agent.classify", "mistral-ocr-latest")
     *   model   - model identifier if known
     *   platform - platform name (openai, mistral, http)
     *   system_prompt - the system prompt text (or description for HTTP tasks)
     */
    public function getMeta(): array;
}
