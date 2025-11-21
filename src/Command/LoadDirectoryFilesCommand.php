<?php

namespace App\Command;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Services\AppService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
//logger interface
use Psr\Log\LoggerInterface;
#[AsCommand( 'app:load-storage', description: 'load storage adapters')]
class LoadDirectoryFilesCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em,
                                private readonly LoggerInterface $logger,
                                ?string $name = null)
    {
        parent::__construct($name);

    }

    protected function configure(): void
    {
        $this
//            ->addArgument('dir', InputArgument::OPTIONAL, 'path to directory root',  $this->bag->get('kernel.project_dir'))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('dir');

        $this->importDirectory($directory);
        $this->em->flush();
        $io->success('Import complete');

        return Command::SUCCESS;
    }


    public function importDirectory(string $directory, array $options = []): void
    {

        $em = $this->em;
//        $root = (new File())
//            ->setIsDir(true)
//            ->setName($directory);
//        $em->persist($root);
        $root = null; // can't figure out how to only open the top level, so this is a hack.
        $finder = new Finder();
        $finder
            ->ignoreVCSIgnored(true)
            ->in($directory);
//        foreach ($finder->directories() as $directory) {
//            dd($directory);
//        }
//        dd($finder->directories());

        // could do this by root only, too.
        $query = $em->createQuery(
            sprintf('DELETE FROM %s e', File::class)
        )->execute();

        $dir = null;
        $dirs = [];
        foreach ($finder as $fileInfo) {
            $name = $fileInfo->getFilename();
            $f = (new File())
                ->setIsDir($fileInfo->isDir())
                ->setPath($fileInfo->getRelativePathname())
                ->setName($name)
            ;

            if ($parentName = $fileInfo->getRelativePath()) {
                // symbolic links, like base-bundle, don't work right
                if (!array_key_exists($parentName, $dirs)) {
                    continue;
                }
                assert(array_key_exists($parentName, $dirs), sprintf("Missing %s in %s (%s)", $parentName, $fileInfo->getPathname(), $directory));
//                dd($fileInfo, $parentName, $dirs[$parentName]);
                $dir = $dirs[$parentName];
            } else {
                $dir = $root;
            }
            $f->setParent($dir);
//            dd($fileInfo, $parentName );

            $em->persist($f);
            if ($fileInfo->isDir()) {
                $dirs[$fileInfo->getRelativePathname()] = $f;
                $f
//                    ->setName($fileInfo->getFilename())
                    ->setIsDir(true);
                $this->logger->warning("Directory", [$f->getName(), $parentName]);
            } else {
                $f
                    ->setIsDir(false)
                    ->setName($fileInfo->getFilename());
                $this->logger->info(sprintf("Adding %s to %s", $f->getName(), $f->getParent()));
            }
        }
        $em->flush();

    }


}
