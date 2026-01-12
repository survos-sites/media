<?php
declare(strict_types=1);

namespace App\Command;

use App\Repository\AssetRepository;
use App\Repository\MediaRepository;
use App\Repository\ThumbRepository;
use App\Service\ImageTaggingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tag-images',
    description: 'Tag a single image URL with OpenAI Vision and return JSON (tags, safety, description, time period).'
)]
final class TagImagesCommand
{
    public function __construct(
        private readonly ?ImageTaggingService $tagger=null,
        private EntityManagerInterface $entityManager,
        private AssetRepository $mediaRepository,
    ) {

    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Image URL (http, https, or data URI)')]
        string $url,

        #[Option('Detail level: low or high', shortcut: 'd')]
        string $detail = 'low',

        #[Option('Language for description output')]
        string $lang = 'en',

        #[Option('Year context to improve tagging')]
        ?string $year = null,

        #[Option('Location context to improve tagging')]
        ?string $location = null,

        #[Option('Existing human-added tags, comma separated')]
        ?string $knownTagsCsv = null,

        #[Option('Free-form contextual hint for better tagging')]
        ?string $hint = null,

        #[Option('Output path to save JSON results')]
        ?string $jsonOut = null,

        #[Option('Dry run without calling OpenAI','dry')]
        bool $dryRun = false,
    ): int {
        $io->title('OpenAI Image Tagger (single URL)');

        $qb = $this->assetRepository->createQueryBuilder('t')
            ->join('t.media', 'm')
            ->where('t.liipCode = :size')
            ->setParameter('size', 'medium')
            ->andWhere('m.description IS NULL');


        if (!str_starts_with($url, 'http://')
            && !str_starts_with($url, 'https://')
            && !str_starts_with($url, 'data:')
        ) {
            $io->error('URL must start with http://, https://, or data:.');
            return Command::INVALID;
        }

        if (!\in_array($detail, ['low', 'high'], true)) {
            $io->error('--detail must be "low" or "high".');
            return Command::INVALID;
        }

        $io->writeln(sprintf('<info>URL:</info> %s', $url));
        $io->writeln(sprintf('<info>Detail:</info> %s', $detail));
        if ($year)     { $io->writeln('<info>Year:</info> ' . $year); }
        if ($location) { $io->writeln('<info>Location:</info> ' . $location); }
        if ($knownTagsCsv) { $io->writeln('<info>Known tags:</info> ' . $knownTagsCsv); }
        if ($hint)     { $io->writeln('<info>Hint:</info> ' . $hint); }
        $io->writeln('<info>Lang:</info> ' . ($lang ?? 'en'));

        $result = [
            'tags'        => [],
            'safety'      => 'unknown',
            'description' => '',
            'time_period' => null,
            'confidence'  => 'low',
        ];

        if ($dryRun) {
            $io->note('DRY RUN — returning a synthetic example payload.');
            $result = [
                'tags'        => ['test', 'dry-run', 'no-api-call'],
                'safety'      => 'safe',
                'description' => 'This is a dry-run placeholder.',
                'time_period' => null,
                'confidence'  => 'low',
            ];
        } else {
            try {
                $result = $this->tagger->tagImageUrl($url, [
                    'detail'       => $detail,
                    'lang'         => $lang,
                    'year'         => $year,
                    'location'     => $location,
                    'knownTagsCsv' => $knownTagsCsv,
                    'hint'         => $hint,
                ]);
            } catch (\Throwable $e) {
                $io->error('OpenAI call failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Pretty-print to console
        $io->newLine();
        $io->writeln('<info>Result JSON:</info>');
        $io->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Optional: write to file
        if ($jsonOut) {
            try {
                $dir = \dirname($jsonOut);
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                        throw new \RuntimeException("Failed to create directory: $dir");
                    }
                }
                file_put_contents($jsonOut, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $io->success("Wrote JSON → {$jsonOut}");
            } catch (\Throwable $e) {
                $io->warning('Failed to write --json-out file: ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
