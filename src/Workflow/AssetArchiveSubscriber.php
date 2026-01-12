<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Asset;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\WorkflowEvents;
use RuntimeException;

final class AssetArchiveSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly FilesystemOperator $archiveStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkflowEvents::COMPLETED . '.asset.archive' => 'onArchive',
        ];
    }

    public function onArchive(CompletedEvent $event): void
    {
        $asset = $event->getSubject();
        if (!$asset instanceof Asset) {
            return;
        }

        if (!$asset->tempFilename || !is_file($asset->tempFilename)) {
            throw new RuntimeException('Missing local file for archive.');
        }

        $ext = $asset->ext ?? 'bin';
        $hex = bin2hex($asset->id);
        $shard = substr($hex, 0, 3);
        $key = sprintf('o/%s/%s.%s', $shard, $hex, $ext);

        $stream = fopen($asset->tempFilename, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed opening local file for archive.');
        }

        $this->archiveStorage->writeStream($key, $stream);
        fclose($stream);

        $asset->storageBackend = 's3';
        $asset->storageKey = $key;
    }
}
