<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\AssetAiTask;
use App\Ai\AssetAiTaskRunner;
use App\Ai\Task\AssetAiTaskInterface;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media', name: 'asset_')]
final class AssetController extends AbstractController
{
    /** @var array<string, array>  taskValue => getMeta() result, built once */
    private array $taskMetaCache = [];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetAiTaskRunner $runner,
        private readonly EntityManagerInterface $em,
        #[TaggedIterator('app.ai_task')]
        private readonly iterable $taskServices = [],
    ) {
    }

    private function taskMeta(): array
    {
        if ($this->taskMetaCache === []) {
            foreach ($this->taskServices as $service) {
                $this->taskMetaCache[$service->getTask()->value] = $service->getMeta();
            }
        }
        return $this->taskMetaCache;
    }

    /** Browse grid — most recent first, simple pagination. */
    #[Route('', name: 'browse')]
    public function browse(Request $request): Response
    {
        $page    = max(1, (int) $request->query->get('page', 1));
        $limit   = 24;
        $offset  = ($page - 1) * $limit;

        // Optional filters
        $type    = $request->query->get('type');
        $marking = $request->query->get('marking');
        $search  = $request->query->get('q');

        $qb = $this->assetRepository->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($type) {
            $qb->andWhere('a.aiDocumentType = :type')->setParameter('type', $type);
        }
        if ($marking) {
            $qb->andWhere('a.marking = :marking')->setParameter('marking', $marking);
        }
        if ($search) {
            $qb->andWhere(
                $qb->expr()->orX(
                    'a.aiTitle LIKE :q',
                    'a.aiDescription LIKE :q',
                    'a.originalUrl LIKE :q',
                )
            )->setParameter('q', '%' . $search . '%');
        }

        $assets = $qb->getQuery()->getResult();
        $total  = (int) $this->assetRepository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()->getSingleScalarResult();

        // Facet counts for the sidebar
        $types = $this->em->createQuery(
            'SELECT a.aiDocumentType as type, COUNT(a.id) as cnt
             FROM App\Entity\Asset a
             WHERE a.aiDocumentType IS NOT NULL
             GROUP BY a.aiDocumentType
             ORDER BY cnt DESC'
        )->getResult();

        return $this->render('asset/browse.html.twig', [
            'assets'     => $assets,
            'total'      => $total,
            'page'       => $page,
            'pages'      => (int) ceil($total / $limit),
            'limit'      => $limit,
            'types'      => $types,
            'filter'     => compact('type', 'marking', 'search'),
            'markings'   => ['new', 'downloaded', 'analyzed', 'complete', 'ai_ready', 'failed'],
        ]);
    }

    /** Detail page for a single asset with AI task runner UI. */
    #[Route('/{id}', name: 'show')]
    public function show(Asset $asset): Response
    {
        $tasks = AssetAiTask::cases();

        // Index completed results for template convenience
        $completedMap = [];
        foreach ($asset->aiCompleted as $entry) {
            $completedMap[$entry['task']] = $entry;
        }

        return $this->render('asset/show.html.twig', [
            'asset'        => $asset,
            'tasks'        => $tasks,
            'taskMeta'     => $this->taskMeta(),
            'completedMap' => $completedMap,
            'pipelines'    => [
                'quick' => AssetAiTask::quickScanPipeline(),
                'full'  => AssetAiTask::fullEnrichmentPipeline(),
            ],
        ]);
    }

    /**
     * HTMX/JSON endpoint: run a single named task and return a result fragment.
     * POST /assets/{id}/task/{taskName}
     */
    #[Route('/{id}/task/{taskName}', name: 'run_task', methods: ['POST'])]
    public function runTask(Asset $asset, string $taskName, Request $request): Response
    {
        $taskEnum = AssetAiTask::tryFrom($taskName);
        if ($taskEnum === null) {
            if ($request->isXmlHttpRequest() || $request->headers->get('HX-Request')) {
                return new JsonResponse(['error' => "Unknown task: {$taskName}"], 400);
            }
            $this->addFlash('danger', "Unknown task: {$taskName}");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        // Inject at front of queue and run
        $originalQueue  = $asset->aiQueue;
        $asset->aiQueue = [$taskName, ...$originalQueue];

        try {
            $ran = $this->runner->runNext($asset);
        } catch (\Throwable $e) {
            if ($request->isXmlHttpRequest() || $request->headers->get('HX-Request')) {
                return new JsonResponse(['error' => $e->getMessage()], 500);
            }
            $this->addFlash('danger', "Task failed: {$e->getMessage()}");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        // Find the result we just recorded
        $result = null;
        foreach (array_reverse($asset->aiCompleted) as $entry) {
            if ($entry['task'] === $taskName) {
                $result = $entry;
                break;
            }
        }

        // HTMX: return a log entry fragment to prepend into #task-log
        if ($request->headers->get('HX-Request')) {
            return $this->render('asset/_task_result_log.html.twig', [
                'entry' => $result ?? ['task' => $taskName, 'at' => date('Y-m-d H:i:s'), 'result' => ['failed' => true, 'error' => 'No result recorded']],
            ]);
        }

        $this->addFlash('success', "Task {$taskName} completed.");
        return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
    }

    /**
     * Enqueue a pipeline and redirect back.
     * POST /assets/{id}/pipeline/{name}
     */
    #[Route('/{id}/pipeline/{name}', name: 'enqueue_pipeline', methods: ['POST'])]
    public function enqueuePipeline(Asset $asset, string $name): Response
    {
        $tasks = match ($name) {
            'quick' => AssetAiTask::quickScanPipeline(),
            'full'  => AssetAiTask::fullEnrichmentPipeline(),
            default => null,
        };

        if ($tasks === null) {
            $this->addFlash('danger', "Unknown pipeline: {$name}");
            return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
        }

        $asset->aiQueue = [];
        $this->runner->enqueue($asset, $tasks);
        $this->addFlash('success', count($tasks) . " tasks enqueued ({$name} pipeline).");

        return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
    }

    /**
     * Toggle the aiLocked flag.
     * POST /assets/{id}/lock
     */
    #[Route('/{id}/lock', name: 'toggle_lock', methods: ['POST'])]
    public function toggleLock(Asset $asset): Response
    {
        $asset->aiLocked = !$asset->aiLocked;
        $this->em->flush();
        $state = $asset->aiLocked ? 'locked' : 'unlocked';
        $this->addFlash('info', "Asset {$state}.");
        return $this->redirectToRoute('asset_show', ['id' => $asset->id]);
    }
}
