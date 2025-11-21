<?php

namespace App\Command;

use App\Entity\File;
use App\Entity\Storage;
use App\Repository\FileRepository;
use App\Repository\StorageRepository;
use App\Workflow\IFileWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\DirectoryAttributes;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StorageBundle\Message\DirectoryListingMessage;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsCommand('app:sync-storage', 'iterate and dispatch an event though storage directories')]
final class SyncStorageCommand extends Command
{


    public function __construct(
        private readonly StorageService $storageService,
        private MessageBusInterface $messageBus,
        private FileRepository $fileRepository,
        private StorageRepository $storageRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        #[Target(IFileWorkflow::WORKFLOW_NAME)] private WorkflowInterface $fileWorkflow,
    )
    {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle                                                                                          $io,
        #[Argument(description: 'zone id, e.g. local.storage', name: 'zone')] ?string $zoneId=null,
        #[Argument('path name within zone')] string        $path='/',
        #[Option] bool $recursive = false,
        #[Option("Dispatch an transition event")] bool $dispatch = false,
        #[Option] string $transport='sync', // for the root, sync by default

    ): int
    {
        if (!$zoneId) {
            $zoneChoices = [];
            foreach ($this->storageService->getZones() as $storageId => $zone) {
                $adapter = $this->storageService->getAdapter($storageId);
                $model = $this->storageService->getAdapterModel($storageId);
                $zoneChoices[] = $storageId; // sprintf('%s %s (%s)', $zoneId, pathinfo($adapter::class), $model->bucket);
            }
            $zoneId = $io->askQuestion(new ChoiceQuestion("storage key (from flysytem)", $zoneChoices));
        }
        // this is a Flysystem/Filesystem class, which flysystem calls "storage"
        $zone = $this->storageService->getZone($zoneId);

        if (!$dispatch) {
            $io->warning('use --dispatch to do something besides populate database');
        }
//        $storage = $this->storageService->getZone($zoneId);

        $path = ltrim($path, '/');
//        $adapter = $this->storageService->getAdapter($zoneId);
        $zone = $this->storageService->getZone($zoneId);
        if (!$storage = $this->storageRepository->findOneBy(['code' => $zoneId])) {
//            assert(false, "missing a storage entity??");
            $storage = new Storage();
            $storage->setCode($zoneId);
            $this->entityManager->persist($storage);
        }

        if (!$dirEntity = $this->fileRepository->findOneBy([
            'storage' => $storage,
            'path' => $path
        ])) {
            $dirEntity = new File($storage, $path, isDir: true, isPublic: true);
            $dirEntity->setName($zoneId);
            $this->entityManager->persist($dirEntity);
        }
        $this->entityManager->flush();
        $io->writeln("File and Storage entities written");

        if ($dispatch) {
            $stamps = [];
            if ($transport) {
                $stamps[] = new TransportNamesStamp([$transport]);
            }
            $this->entityManager->flush();
            if ($this->fileWorkflow->can($dirEntity, IFileWorkflow::TRANSITION_LIST)) {
//                $this->logger->warning("Dispatching directory listing for " . $file->getPath());
                $this->messageBus->dispatch(new TransitionMessage(
                    $dirEntity->getId(),
                    File::class,
                    IFileWorkflow::TRANSITION_LIST,
                    IFileWorkflow::WORKFLOW_NAME),
                    $stamps);
            } else {
                $this->logger->warning(sprintf("cannot list %s from %s ",
                    $dirEntity->getPath(), $dirEntity->getMarking()));
            }

            $io->success("$zoneId $path dispatched");
        }
        return self::SUCCESS;

    }

}
