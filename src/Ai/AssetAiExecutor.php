<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\MediaBundle\Service\SidecarService;

/**
 * Runs a single ai-workflow-bundle task against an Asset (via {@see AssetSubject})
 * and persists the result: cached on the S3 sidecar (cache-aside) and recorded as
 * claims. This is the one place that bridges Asset → ai-workflow, replacing the
 * removed ai-pipeline-bundle handler layer. Shared by AssetController (sync HTTP)
 * and AssetWorkflow (queue/transition driven).
 */
final class AssetAiExecutor
{
    public function __construct(
        private readonly TaskRegistry $registry,
        private readonly SidecarService $sidecar,
        private readonly ?ClaimIngestor $claimIngestor = null,
    ) {
    }

    /**
     * @param array<string,mixed> $context runtime hints (e.g. ['max_pages' => 3])
     *
     * @return array{ok:bool,cached:bool,response:array<string,mixed>,reason?:string}
     */
    public function run(Asset $asset, string $task, array $context = [], bool $force = false): array
    {
        $taskObj = $this->registry->get($task);
        if ($taskObj === null) {
            return ['ok' => false, 'cached' => false, 'response' => [], 'reason' => 'task handler not found'];
        }

        $subject = new AssetSubject($asset, $context);
        if (!$taskObj->supports($subject)) {
            return ['ok' => false, 'cached' => false, 'response' => [], 'reason' => 'not supported for this asset'];
        }

        if (!$force && null !== ($cached = $this->sidecar->read($asset->id, $task))) {
            return ['ok' => true, 'cached' => true, 'response' => $cached];
        }

        $result = $taskObj->run($subject);
        $response = (array) ($result->meta?->response ?? []);

        if ($this->sidecar->isAvailable()) {
            $this->sidecar->remember($asset->id, $task, static fn (): array => $response, force: true);
        }
        if ($this->claimIngestor !== null && !empty($result->claims)) {
            try {
                $this->claimIngestor->record(
                    $subject->getWorkflowScope(),
                    Asset::class,
                    $asset->id,
                    $task,
                    $result->claims,
                    $result->meta,
                );
            } catch (\Throwable) {
                // Claims persistence is best-effort; the sidecar is the authority.
            }
        }

        return ['ok' => true, 'cached' => false, 'response' => $response];
    }
}
