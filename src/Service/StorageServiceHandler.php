<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\File;
use App\Entity\Storage;
use App\Repository\FileRepository;
use App\Repository\StorageRepository;
use App\Workflow\FileWorkflow;
use App\Workflow\IFileWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class StorageServiceHandler
{
    public function __construct(
        private FileRepository                                            $fileRepository,
        private StorageRepository                                         $storageRepository,
        private EntityManagerInterface                                    $entityManager,
        private readonly LoggerInterface                                  $logger,
        private StorageService                                            $storageService,
        private MessageBusInterface                                       $messageBus,
        #[Target(IFileWorkflow::WORKFLOW_NAME)] private WorkflowInterface $fileWorkflow,
        private readonly AsyncQueueLocator $asyncQueueLocator,
    )
    {
    }

    private array $seen = []; // avoid so many lookups, use redis bloom filter in production

    public function syncDirectoryListing(string $zoneId, string $path): array
    {
        $results = [];
        $path = ltrim($path, '/');
//        $adapter = $this->storageService->getAdapter($zoneId);
        $zone = $this->storageService->getZone($zoneId);
        assert($zone);
        if (!$storage = $this->storageRepository->find(Storage::calcCode($zoneId))) {
            assert(false, "missing a storage entity??");
//            $storage = new Storage();
//            $storage->setCode($zoneId);
//            $this->entityManager->persist($storage);
        }
        $seen = &$this->seen;
        // there must be a doctrine way to do this!
        foreach ($this->fileRepository->findBy(['storage' => $storage]) as $file) {
            $fileId = $file->id;
            $seen[$fileId] = $file;
        }

        $listingCount = 0;
        $code = File::calcCode($storage, $path);
        $root = $this->fileRepository->find($code);
        assert($root, "we should have a root unless we haven't persisted yet");
        if (!$root) return [];

        $iterator = $zone->listContents($path, false);
        /** @var FileAttributes|DirectoryAttributes $file */
        $root->dirCount = 0;
        $root->fileCount = 0;
        foreach ($iterator as $storageAttributes) {
            $listingCount++;
            $code = File::calcCode($storage, $filePath = $storageAttributes->path());
            $fileEntity = $seen[$code] ?? null;

            if (!$fileEntity instanceof File) {
                $fileEntity = new File(
                    $storage,
                    $filePath,
                    isDir: $storageAttributes->isDir(),
                );
                $this->entityManager->persist($fileEntity);
                $root?->addChild($fileEntity);
                $seen[$code] = $fileEntity;
            }

            if ($fileEntity->isDir) {
                $root->dirCount++;
            } else {
                $root->fileCount++;
            }

//            if ($unixTime = $storageAttributes->lastModified()) {
//                dump($unixTime);
//                $this->logger->error((string)$unixTime);
//                $lastModified = (new \DateTime())->setTimestamp($unixTime);
//                $fileEntity
//                    ->setLastModified($lastModified);
//            }
            $fileEntity->isPublic = $storageAttributes->visibility() === 'public';
            $fileEntity->name = pathinfo($path, PATHINFO_BASENAME);
            if ($storageAttributes->isFile()) {
                $fileEntity->fileSize = $storageAttributes->fileSize();
            }
            $results[] = $fileEntity;
        }
//        $root?->setListingCount($listingCount);
        assert($listingCount === count($results), 'count is wrong');

        // An empty directory is a valid listing; avoid failing the transition.
        if ($listingCount === 0) {
            $this->logger->info(sprintf('Empty directory listing for %s/%s', $zoneId, $path));
        }

        $this->entityManager->flush();
        return $results;
    }

    public function dispatchDirectoryRequests(array $results)
    {
        foreach ($results as $file) {
            assert($file instanceof File);
            if ($file->isDir) {
                if ($this->fileWorkflow->can($file, IFileWorkflow::TRANSITION_LIST)) {
                    $this->logger->warning("Dispatching directory listing for " . $file->path);
                    $message = new TransitionMessage(
                        $file->getId(),
                        File::class,
                        IFileWorkflow::TRANSITION_LIST,
                        IFileWorkflow::WORKFLOW_NAME);
                    $stamps = $this->asyncQueueLocator->stamps($message);
                    $this->messageBus->dispatch($message, $stamps);

                } else {
                    // maybe if it's already been listed?
                    $this->logger->warning(sprintf("cannot list %s from %s ",
                        $file->path, $file->marking));
                }

            }
        }
    }

}
