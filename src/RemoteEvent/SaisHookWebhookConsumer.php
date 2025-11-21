<?php

namespace App\RemoteEvent;

use App\Entity\Media;
use App\Webhook\SaisHookRequestMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Psr\Log\LoggerInterface;

#[AsRemoteEventConsumer('sais-hook')]
final class SaisHookWebhookConsumer implements ConsumerInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function consume(RemoteEvent $event): void
    {
        // For example, log the event or process it further
        $this->logger->info('Received Sais Hook Event', [
            'payload' => $event->getPayload()
        ]);
    }
}
