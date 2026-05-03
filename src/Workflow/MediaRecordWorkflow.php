<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\MediaRecord;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\TransitionEvent;

final class MediaRecordWorkflow
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsTransitionListener(MediaRecordFlow::WORKFLOW_NAME, MediaRecordFlow::TRANSITION_SPLIT_ASSETS)]
    public function onSplitAssets(TransitionEvent $event): void
    {
        $record = $this->getRecord($event);
        $record->context ??= [];
        $record->context['split'] = [
            'status' => 'queued',
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'note' => 'Split workflow scaffolded; implement PDF/page extraction next.',
        ];
        $this->entityManager->flush();
    }

    #[AsTransitionListener(MediaRecordFlow::WORKFLOW_NAME, MediaRecordFlow::TRANSITION_AI_TASK)]
    public function onAiTask(TransitionEvent $event): void
    {
        $record = $this->getRecord($event);
        $nextTask = array_shift($record->aiQueue);
        if (!is_string($nextTask) || $nextTask === '') {
            return;
        }

        $record->aiCompleted[] = [
            'task' => $nextTask,
            'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'result' => [
                'status' => 'queued',
                'note' => 'MediaRecord AI workflow scaffolded; task execution to be implemented.',
            ],
        ];

        $this->logger->info('MediaRecord AI task scaffold processed for {id}: {task}', [
            'id' => $record->id,
            'task' => $nextTask,
        ]);

        $this->entityManager->flush();
    }

    private function getRecord(TransitionEvent $event): MediaRecord
    {
        $subject = $event->getSubject();
        if (!$subject instanceof MediaRecord) {
            throw new \RuntimeException('Expected MediaRecord subject.');
        }

        return $subject;
    }
}
