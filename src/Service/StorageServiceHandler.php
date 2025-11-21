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
use Survos\SaisBundle\Service\SaisClientService;
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
    )
    {
    }

    public function syncDirectoryListing(string $zoneId, string $path): array
    {
        $results = [];
        $path = ltrim($path, '/');
//        $adapter = $this->storageService->getAdapter($zoneId);
        $zone = $this->storageService->getZone($zoneId);
        if (!$storage = $this->storageRepository->findOneBy(['code' => $zoneId])) {
//            assert(false, "missing a storage entity??");
            $storage = new Storage();
            $storage->setCode($zoneId);
            $this->entityManager->persist($storage);
        }

        $listingCount = 0;
        $storage
//            ->setRoot($adapter->getRoot())
//            ->setAdapter($adapter::class)
        ;
        $dirEntity = $this->fileRepository->findOneBy([
            'storage' => $storage,
            'path' => $path
        ]);

        $iterator = $zone->listContents($path, false);
        /** @var FileAttributes|DirectoryAttributes $file */
        foreach ($iterator as $file) {
            $listingCount++;
            $this->logger->info($listingCount . " {$file['type']}: {$file['path']} ");
            $path = $file->path();
            if (!$fileEntity = $this->fileRepository->findOneBy(
                [
                    'storage' => $storage,
                    'path' => $path,
                ])) {
                $fileEntity = new File($storage, $path,
                    isDir: $file->isDir()
                );
                $this->entityManager->persist($fileEntity);
                $dirEntity?->addChild($fileEntity);
            }

//            if ($unixTime = $file->lastModified()) {
//                dump($unixTime);
//                $this->logger->error((string)$unixTime);
//                $lastModified = (new \DateTime())->setTimestamp($unixTime);
//                $fileEntity
//                    ->setLastModified($lastModified);
//            }
            $fileEntity
                ->setIsPublic($file->visibility() === 'public');
            $fileEntity->setName(pathinfo($path, PATHINFO_BASENAME));
            if ($file->isFile()) {
                $fileEntity->setFileSize($file->fileSize());
            }
            $results[] = $fileEntity;
        }
        $dirEntity?->setListingCount($listingCount);
        assert($listingCount == count($results), "count is wrong");
        assert($listingCount, "no listing");
        $this->entityManager->flush();
        return $results;
    }

    public function dispatchDirectoryRequests(array $results)
    {
        $stamps = [];
        /** @var File $file */
        foreach ($results as $file) {
            if ($file->getIsDir()) {
                if ($this->fileWorkflow->can($file, IFileWorkflow::TRANSITION_LIST)) {
                    $this->logger->warning("Dispatching directory listing for " . $file->getPath());
                    $this->messageBus->dispatch(new TransitionMessage(
                        $file->getId(),
                        File::class,
                        IFileWorkflow::TRANSITION_LIST,
                        IFileWorkflow::WORKFLOW_NAME),
                        $stamps);
                } else {
                    $this->logger->warning(sprintf("cannot list %s from %s ",
                        $file->getPath(), $file->getMarking()));

                }

//                $this->messageBus->dispatch(new DirectoryListingMessage(
//                    $zoneId,
//                    'dir',
//                    $file->getPath(),
//                ));

            }
        }
    }

    #[AsMessageHandler()]
    public function handleStorage(DirectoryListingMessage $message)
    {
        // see https://flysystem.thephpleague.com/docs/usage/directory-listings/
        $zoneId = $message->zoneId;
        $root = $message->path;
        $this->logger->warning("handling directory request for $zoneId/" . $message->path);
        $results = $this->syncDirectoryListing($zoneId, $root);
        $this->dispatchDirectoryRequests($results);
    }
}
