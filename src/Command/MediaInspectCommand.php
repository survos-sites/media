<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\Option;

#[AsCommand('media:inspect', 'Inspect media readiness on the media server')]
final class MediaInspectCommand
{
    public function __construct(private readonly AssetRepository $repo) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter by client code')] ?string $client = null,
    ): int {
        $assets = $client
            ? $this->repo->findByClient($client)
            : $this->repo->findAll();

        $io->table(
            ['ID', 'Clients', 'Archived', 'Analyzed', 'URL'],
            array_map(fn(Asset $a) => [
                $a->id,
                implode(',', $a->clients),
                $a->archiveUrl ? 'yes' : 'no',
                property_exists($a, 'thumbhash') && $a->thumbhash ? 'yes' : 'no',
                $a->originalUrl,
            ], $assets)
        );

        return Command::SUCCESS;
    }
}
