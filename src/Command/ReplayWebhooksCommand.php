<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AssetRepository;
use App\Workflow\AssetFlow as WF;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Replays webhook callbacks for assets that are already analyzed but whose
 * callback_url was unreachable (e.g. localhost) when they originally completed.
 *
 * Usage:
 *   php bin/console media:replay-webhooks
 *   php bin/console media:replay-webhooks --dry-run
 *   php bin/console media:replay-webhooks --id=fd1230ed5a6267c0
 */
#[AsCommand(name: 'media:replay-webhooks', description: 'Replay webhook callbacks for analyzed assets')]
final class ReplayWebhooksCommand extends Command
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent without firing')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Replay a single asset by ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $singleId = $input->getOption('id');

        $assets = $singleId
            ? array_filter([$this->assetRepository->find($singleId)])
            : $this->assetRepository->findBy(['marking' => WF::PLACE_ANALYZED]);

        $fired = 0;
        $skipped = 0;

        foreach ($assets as $asset) {
            $callbackUrl = $asset->context['callback_url'] ?? null;
            if (!$callbackUrl) {
                $io->comment(sprintf('  skip %s — no callback_url', $asset->id));
                $skipped++;
                continue;
            }

            $payload = [
                'event'       => 'asset.analyzed',
                'assetId'     => $asset->id,
                'originalUrl' => $asset->originalUrl,
                'clients'     => $asset->clients,
                'marking'     => $asset->marking,
                'mime'        => $asset->mime,
                'width'       => $asset->width,
                'height'      => $asset->height,
                'archiveUrl'  => $asset->archiveUrl,
                'smallUrl'    => $asset->smallUrl,
                'context'     => [
                    'ocr'       => $asset->context['ocr']       ?? null,
                    'ocr_chars' => $asset->context['ocr_chars'] ?? null,
                    'thumbhash' => $asset->context['thumbhash'] ?? null,
                    'colors'    => $asset->context['colors']    ?? null,
                    'phash'     => $asset->context['phash']     ?? null,
                    'path'      => $asset->context['path']      ?? null,
                    'tenant'    => $asset->context['tenant']    ?? null,
                    'image_id'  => $asset->context['image_id']  ?? null,
                ],
            ];

            $io->text(sprintf('  %s → %s (image_id=%s)', $asset->id, $callbackUrl, $asset->context['image_id'] ?? '?'));

            if ($dryRun) {
                $skipped++;
                continue;
            }

            try {
                $options = ['json' => $payload, 'timeout' => 10];
                if (str_contains($callbackUrl, '.wip')) {
                    $options['proxy'] = 'http://127.0.0.1:7080';
                }
                $response = $this->httpClient->request('POST', $callbackUrl, $options);
                $status = $response->getStatusCode();
                $io->text(sprintf('    → HTTP %d', $status));
                $fired++;
            } catch (\Throwable $e) {
                $io->error(sprintf('    failed: %s', $e->getMessage()));
            }
        }

        $io->success(sprintf('Fired: %d  Skipped: %d', $fired, $skipped));
        return Command::SUCCESS;
    }
}
