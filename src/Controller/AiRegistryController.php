<?php
declare(strict_types=1);

namespace App\Controller;

use App\Ai\AssetAiTask;
use Survos\AiPipelineBundle\Task\AiTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ai', name: 'asset_')]
final class AiRegistryController extends AbstractController
{
    public function __construct(
        private readonly AiTaskRegistry $registry,
    ) {
    }

    #[Route('/tasks', name: 'task_registry')]
    public function registry(): Response
    {
        $taskMap = $this->registry->getTaskMap();

        // Build pipeline DTOs and value indexes
        $quickPipeline = $this->buildPipelineDtos(AssetAiTask::quickScanPipeline(), $taskMap);
        $fullPipeline = $this->buildPipelineDtos(AssetAiTask::fullEnrichmentPipeline(), $taskMap);
        $quickValues = array_column($quickPipeline, 'value');
        $fullValues = array_column($fullPipeline, 'value');

        $enumByValue = [];
        foreach (AssetAiTask::cases() as $case) {
            $enumByValue[$case->value] = $case;
        }

        $tasks = [];
        foreach (AssetAiTask::cases() as $task) {
            $serviceId = $taskMap[$task->value] ?? null;
            $tasks[] = [
                'name' => $task->name,
                'value' => $task->value,
                'registered' => $serviceId !== null,
                'class' => $serviceId ? basename(str_replace('\\', '/', $serviceId)) : null,
                'inQuick' => in_array($task->value, $quickValues, true),
                'inFull' => in_array($task->value, $fullValues, true),
            ];
        }

        // Include registry-only tasks (not present in enum) for visibility.
        foreach ($taskMap as $taskName => $serviceId) {
            if (isset($enumByValue[$taskName])) {
                continue;
            }

            $tasks[] = [
                'name' => strtoupper($taskName),
                'value' => $taskName,
                'registered' => true,
                'class' => basename(str_replace('\\', '/', $serviceId)),
                'inQuick' => in_array($taskName, $quickValues, true),
                'inFull' => in_array($taskName, $fullValues, true),
            ];
        }

        return $this->render('ai/registry.html.twig', [
            'tasks'     => $tasks,
            'pipelines' => [
                'quick' => $quickPipeline,
                'full'  => $fullPipeline,
            ],
        ]);
    }

    /**
     * @param AssetAiTask[] $pipeline
     * @param array<string,string> $taskMap
     * @return array<int, array{name: string, value: string, registered: bool, class: ?string}>
     */
    private function buildPipelineDtos(array $pipeline, array $taskMap): array
    {
        $rows = [];
        foreach ($pipeline as $task) {
            $serviceId = $taskMap[$task->value] ?? null;
            $rows[] = [
                'name' => $task->name,
                'value' => $task->value,
                'registered' => $serviceId !== null,
                'class' => $serviceId ? basename(str_replace('\\', '/', $serviceId)) : null,
            ];
        }

        return $rows;
    }
}
