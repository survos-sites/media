<?php

namespace App\Workflow;

use App\Entity\File;
use App\Service\StorageServiceHandler;
use Survos\StateBundle\Attribute\Workflow;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use App\Workflow\IFileWorkflow as WF;
class FileWorkflow
{
	public const WORKFLOW_NAME = 'FileWorkflow';

	public function __construct(
        private StorageServiceHandler $storageService,
    )
	{
	}

    private function getFile(GuardEvent|TransitionEvent $event): File
    {
        /** @var File */ return $event->getSubject();
    }

	#[AsGuardListener(WF::WORKFLOW_NAME, WF::TRANSITION_LIST)]
	public function onGuardList(GuardEvent $event): void
	{
        $file = $this->getFile($event);
        if (!$file->isDir) {
            $event->setBlocked(true, "only directories can be listed");
        }
	}

	#[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_LIST)]
	public function onList(TransitionEvent $event): void
	{
        $file = $this->getFile($event);
        $results = $this->storageService->syncDirectoryListing($file->storageId, $file->path);
        $this->storageService->dispatchDirectoryRequests($results);

    }
}
