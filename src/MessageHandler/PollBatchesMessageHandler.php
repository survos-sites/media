<?php

declare(strict_types=1);

namespace App\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Message\ApplyBatchResultsMessage;
use Tacman\AiBatch\Message\PollBatchesMessage;
use Tacman\AiBatch\Service\OpenAiBatchClient;

/**
 * Scheduler-driven poll of mediary's in-flight OpenAI batches (PollBatchesTask, every 2 min).
 *
 * Identical lifecycle to md's: re-check provider status, fold it onto the AiBatch row, and on first
 * completion archive the raw provider output to S3 (durable; OpenAI keeps output ~a month) then hand
 * off to ApplyBatchResultsMessageHandler, which records the results as claims via ClaimIngestor.
 */
#[AsMessageHandler]
final class PollBatchesMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OpenAiBatchClient $client,
        #[Autowire(service: 'archive.storage')]
        private readonly FilesystemOperator $storage,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollBatchesMessage $message): void
    {
        $batches = $this->em->getRepository(AiBatch::class)->findBy(['status' => ['submitted', 'processing']]);
        foreach ($batches as $batch) {
            if ($batch->providerBatchId === null) {
                continue;
            }

            try {
                $job = $this->client->checkBatch($batch->providerBatchId);
            } catch (\Throwable $e) {
                $this->logger->warning('ai-batch poll failed for {id}: {err}', ['id' => $batch->providerBatchId, 'err' => $e->getMessage()]);
                continue;
            }

            $batch->applyProviderStatus($job->status, $job->completedCount, $job->failedCount, $job->outputFileId, $job->errorFileId);

            if ($job->isComplete() && $batch->savedResultPath === null && $job->outputFileId !== null) {
                $lines = [];
                foreach ($this->client->fetchResults($job) as $result) {
                    $lines[] = json_encode($result->raw, JSON_UNESCAPED_UNICODE);
                }
                $key = sprintf('ai-batch/%s/%s/%s.jsonl', trim((string) ($batch->datasetKey ?? '_'), '/') ?: '_', $batch->task, $batch->providerBatchId);
                $this->storage->write($key, implode("\n", $lines) . "\n");
                $batch->savedResultPath = $key;
                $this->em->flush();

                $this->bus->dispatch(new ApplyBatchResultsMessage($batch->id));
                $this->logger->info('ai-batch {id} complete → {key} ({n} results)', ['id' => $batch->providerBatchId, 'key' => $key, 'n' => \count($lines)]);
                continue;
            }

            $this->em->flush();
        }
    }
}
