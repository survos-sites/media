<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use Survos\MediaBundle\Contract\MediaSyncKeys;
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
        // The AI runs against THIS subject, not the raw Asset, so the claim identity is the subject's.
        // Key to the source record (e.g. fortepan 1957) when the asset carries one → claims land under
        // (dataset, record_key) where claims:fetch + the folio read them. Bare assets → the asset id.
        $sourceMeta = $this->asset->sourceMeta ?? [];
        $recordKey = $this->context[MediaSyncKeys::RECORD_KEY] ?? ($sourceMeta[MediaSyncKeys::RECORD_KEY] ?? null);

        return is_string($recordKey) && $recordKey !== '' ? $recordKey : $this->asset->id;
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
        $sourceMeta = $this->asset->sourceMeta ?? [];
        $scope = $this->context['scope']
            ?? $this->context[MediaSyncKeys::DATASET]
            ?? ($sourceMeta[MediaSyncKeys::DATASET] ?? null);

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
