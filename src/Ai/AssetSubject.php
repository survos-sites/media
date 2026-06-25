<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;

/**
 * Adapts a mediary {@see Asset} to the ai-workflow-bundle subject interfaces so
 * its TaskRegistry tasks (observe, ocr_mistral, …) can run directly against an
 * Asset — without the removed ai-pipeline-bundle handler layer.
 */
final class AssetSubject implements WorkflowSubjectInterface, ImageSubjectInterface, ContextSubjectInterface
{
    /** @param array<string,mixed> $context runtime hints merged over the asset's own context */
    public function __construct(
        private readonly Asset $asset,
        private readonly array $context = [],
    ) {
    }

    public function getWorkflowSubjectId(): string
    {
        return $this->asset->id;
    }

    public function getWorkflowSubjectType(): string
    {
        return Asset::class;
    }

    public function getWorkflowScope(): ?string
    {
        // A caller that knows the dataset (e.g. ai/from-url?scope=nara/coll_dde-1200) scopes the
        // claims to it, so `claims:fetch <dataset>` can pull them into that dataset's vault. Bare
        // one-off calls fall back to 'mediary' (the ambient asset-cache scope).
        $scope = $this->context['scope'] ?? null;

        return is_string($scope) && $scope !== '' ? $scope : 'mediary';
    }

    public function isWorkflowLocked(): bool
    {
        return $this->asset->aiLocked;
    }

    public function setWorkflowLocked(bool $locked): void
    {
        $this->asset->aiLocked = $locked;
    }

    public function getWorkflowImageUrl(): ?string
    {
        return $this->asset->originalUrl;
    }

    /** @return array<string,mixed> */
    public function getWorkflowContext(): array
    {
        return array_merge($this->asset->context ?? [], $this->context);
    }
}
