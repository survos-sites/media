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

        // Build a display list enriched with pipeline membership
        $quickValues = array_map(fn(AssetAiTask $t) => $t->value, AssetAiTask::quickScanPipeline());
        $fullValues  = array_map(fn(AssetAiTask $t) => $t->value, AssetAiTask::fullEnrichmentPipeline());

        $tasks = [];
        foreach ($taskMap as $taskName => $serviceId) {
            $tasks[] = [
                'value'      => $taskName,
                'registered' => true,
                'class'      => basename(str_replace('\\', '/', $serviceId)),
                'inQuick'    => in_array($taskName, $quickValues, true),
                'inFull'     => in_array($taskName, $fullValues, true),
            ];
        }

        return $this->render('ai/registry.html.twig', [
            'tasks'     => $tasks,
            'pipelines' => [
                'quick' => $quickValues,
                'full'  => $fullValues,
            ],
        ]);
    }
}
