<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AssetAiTask;
use App\Ai\Task\AssetAiTaskInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ai', name: 'asset_')]
final class AiRegistryController extends AbstractController
{
    /**
     * @param iterable<AssetAiTaskInterface> $taskServices
     */
    public function __construct(
        private readonly iterable $taskServices,
    ) {
    }

    #[Route('/tasks', name: 'task_registry')]
    public function registry(): Response
    {
        // Build a map: task value → registered service class
        $registered = [];
        foreach ($this->taskServices as $service) {
            $registered[$service->getTask()->value] = [
                'class'   => $service::class,
                'service' => $service,
            ];
        }

        $tasks = [];
        foreach (AssetAiTask::cases() as $case) {
            $tasks[] = [
                'enum'       => $case,
                'value'      => $case->value,
                'name'       => $case->name,
                'registered' => isset($registered[$case->value]),
                'class'      => $registered[$case->value]['class'] ?? null,
                'inQuick'    => in_array($case, AssetAiTask::quickScanPipeline(), true),
                'inFull'     => in_array($case, AssetAiTask::fullEnrichmentPipeline(), true),
            ];
        }

        return $this->render('ai/registry.html.twig', [
            'tasks'     => $tasks,
            'pipelines' => [
                'quick' => AssetAiTask::quickScanPipeline(),
                'full'  => AssetAiTask::fullEnrichmentPipeline(),
            ],
        ]);
    }
}
