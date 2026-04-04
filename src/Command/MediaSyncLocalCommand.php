<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand('media:sync-local', 'Sync URL/IIIF manifest directly through local Asset workflow')]
final class MediaSyncLocalCommand
{
    public function __construct(
        private readonly AssetRegistry $assetRegistry,
        private readonly EntityManagerInterface $entityManager,
        #[Target(AssetFlow::WORKFLOW_NAME)]
        private readonly WorkflowInterface $assetWorkflow,
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Image/PDF URL or IIIF manifest URL')]
        string $url,
        #[Option('Client code to attach')]
        string $client = 'cli',
        #[Option('Also run local_ocr after download')]
        bool $ocr = false,
        #[Option('Reset workflow marking and OCR fields before running')]
        bool $reset = false,
    ): int {
        $io->title('media:sync-local');
        $io->text(sprintf('Input: %s', $url));

        $rows = $this->looksLikeManifestUrl($url)
            ? $this->manifestRows($url)
            : [['original_url' => $url, 'context' => []]];

        if ($io->isVerbose()) {
            $io->text(sprintf('Resolved %d ingest row(s).', count($rows)));
            foreach ($rows as $idx => $row) {
                $io->writeln(sprintf('  [%d] %s', $idx + 1, $row['original_url']));
            }
        }

        if ($rows === []) {
            $io->error('No ingestible resources found.');
            return Command::FAILURE;
        }

        $assets = [];
        foreach ($rows as $row) {
            if ($io->isVeryVerbose()) {
                $io->section('ensureAsset');
                $io->writeln(sprintf('url: %s', $row['original_url']));
                $io->writeln('context: ' . json_encode($row['context'], JSON_UNESCAPED_SLASHES));
            }
            $assets[] = $this->assetRegistry->ensureAsset($row['original_url'], $client, false, $row['context']);
        }
        $this->assetRegistry->flush();

        foreach ($assets as $asset) {
            if ($reset) {
                $asset->marking = AssetFlow::PLACE_NEW;
                $asset->localOcrStatus = null;
                $asset->localOcrError = null;
                $asset->localOcrText = null;
                $asset->localOcrConfidence = null;
                $asset->localOcrPrimaryType = null;
                $asset->localOcrSourceUrl = null;
                $asset->localOcrProvider = null;
                $asset->localOcrModel = null;
                $asset->localOcrAt = null;
                if (is_array($asset->context)) {
                    unset($asset->context['local_ocr'], $asset->context['ocr'], $asset->context['ocr_chars']);
                }
                $this->entityManager->flush();
                if ($io->isVeryVerbose()) {
                    $io->writeln('reset: marking + local OCR fields');
                }
            }

            if ($io->isVeryVerbose()) {
                $io->section(sprintf('Asset %s', $asset->id));
                $io->writeln(sprintf('start marking: %s', $asset->marking ?? '-'));
            }
            if ($this->assetWorkflow->can($asset, AssetFlow::TRANSITION_DOWNLOAD)) {
                if ($io->isVeryVerbose()) {
                    $io->writeln('transition: download');
                }
                $this->assetWorkflow->apply($asset, AssetFlow::TRANSITION_DOWNLOAD);
                $this->entityManager->flush();
                if ($io->isVeryVerbose()) {
                    $io->writeln(sprintf('download_url: %s', (string) ($asset->context['download_url'] ?? '-')));
                    $sourceProbe = is_array($asset->context['source_probe'] ?? null) ? $asset->context['source_probe'] : [];
                    $canonicalProbe = is_array($asset->context['canonical_probe'] ?? null) ? $asset->context['canonical_probe'] : [];
                    $smallProbe = is_array($asset->context['small_probe'] ?? null) ? $asset->context['small_probe'] : [];
                    if ($sourceProbe !== []) {
                        $io->writeln(sprintf(
                            'source_probe: %sx%s %s bytes (%s)',
                            (string) ($sourceProbe['width'] ?? '?'),
                            (string) ($sourceProbe['height'] ?? '?'),
                            (string) ($sourceProbe['bytes'] ?? '?'),
                            (string) ($sourceProbe['mime'] ?? '-')
                        ));
                    }
                    if ($canonicalProbe !== []) {
                        $io->writeln(sprintf(
                            'canonical_probe: %sx%s %s bytes (%s)',
                            (string) ($canonicalProbe['width'] ?? '?'),
                            (string) ($canonicalProbe['height'] ?? '?'),
                            (string) ($canonicalProbe['bytes'] ?? '?'),
                            (string) ($canonicalProbe['mime'] ?? '-')
                        ));
                    }
                    if ($smallProbe !== []) {
                        $io->writeln(sprintf(
                            'small_probe: %sx%s %s bytes (%s)',
                            (string) ($smallProbe['width'] ?? '?'),
                            (string) ($smallProbe['height'] ?? '?'),
                            (string) ($smallProbe['bytes'] ?? '?'),
                            (string) ($smallProbe['mime'] ?? '-')
                        ));
                    }
                    $io->writeln(sprintf('archive_url: %s', (string) ($asset->archiveUrl ?? '-')));
                    $io->writeln(sprintf('local_canonical: %s', (string) ($asset->localCanonicalFilename ?? '-')));
                    $io->writeln(sprintf('local_small: %s', (string) ($asset->localSmallFilename ?? '-')));
                    $io->writeln(sprintf('after download marking: %s', $asset->marking ?? '-'));
                }
            }
            if ($ocr && $this->assetWorkflow->can($asset, AssetFlow::TRANSITION_LOCAL_OCR)) {
                if ($io->isVeryVerbose()) {
                    $io->writeln('transition: local_ocr');
                }
                $this->assetWorkflow->apply($asset, AssetFlow::TRANSITION_LOCAL_OCR);
                $this->entityManager->flush();
                if ($io->isVeryVerbose()) {
                    $localOcr = is_array($asset->context['local_ocr'] ?? null) ? $asset->context['local_ocr'] : [];
                    if ($localOcr !== []) {
                        $ocrText = is_array($localOcr['ocr'] ?? null) ? (string) ($localOcr['ocr']['text'] ?? '') : '';
                        $io->writeln(sprintf('local_ocr_status: %s', (string) ($localOcr['status'] ?? $asset->localOcrStatus ?? '-')));
                        $io->writeln(sprintf('local_ocr_ok: %s', ((bool) ($localOcr['ok'] ?? false)) ? 'true' : 'false'));
                        $io->writeln(sprintf('local_ocr_primary_type: %s', (string) ($localOcr['primary_type'] ?? $asset->localOcrPrimaryType ?? '-')));
                        $io->writeln(sprintf('local_ocr_text_chars: %d', mb_strlen($ocrText)));
                        if (is_string($localOcr['error'] ?? null) && $localOcr['error'] !== '') {
                            $io->writeln(sprintf('local_ocr_error: %s', (string) $localOcr['error']));
                        }
                    }
                    $io->writeln(sprintf('after local_ocr marking: %s', $asset->marking ?? '-'));
                }
            }
        }

        $table = [];
        foreach ($assets as $asset) {
            $sourceProbe = is_array($asset->context['source_probe'] ?? null) ? $asset->context['source_probe'] : [];
            $canonicalProbe = is_array($asset->context['canonical_probe'] ?? null) ? $asset->context['canonical_probe'] : [];
            $table[] = [
                $asset->id,
                $asset->marking ?? '-',
                $asset->mime ?? '-',
                sprintf('%sx%s', (string) ($asset->width ?? '?'), (string) ($asset->height ?? '?')),
                (string) ($asset->size ?? '-'),
                sprintf('%sx%s', (string) ($sourceProbe['width'] ?? '?'), (string) ($sourceProbe['height'] ?? '?')),
                (string) ($sourceProbe['bytes'] ?? '-'),
                (string) ($canonicalProbe['bytes'] ?? '-'),
                (string) ($asset->context['download_url'] ?? '-'),
                $asset->archiveUrl ?? '-',
                $asset->localCanonicalFilename ?? '-',
                $asset->localSmallFilename ?? '-',
                $this->urlGenerator->generate('asset_show', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }
        $io->table(['Asset', 'Marking', 'Mime', 'Final WxH', 'Final Bytes', 'Source WxH', 'Source Bytes', 'Canonical Bytes', 'Downloaded From', 'Archive', 'Local Canonical', 'Local Small', 'Show'], $table);
        $io->success(sprintf('Synced %d asset(s) through local workflow.', count($assets)));

        return Command::SUCCESS;
    }

    private function looksLikeManifestUrl(string $url): bool
    {
        return str_ends_with(strtolower($url), '/manifest');
    }

    /** @return list<array{original_url:string,context:array<string,mixed>}> */
    private function manifestRows(string $manifestUrl): array
    {
        try {
            $response = $this->httpClient->request('GET', $manifestUrl);
            if ($response->getStatusCode() >= 400) {
                return [];
            }
            $manifest = $response->toArray(false);
            if (!is_array($manifest)) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $manifestId = $this->extractUrl($manifest) ?? $manifestUrl;
        $thumbnailUrl = $this->extractUrl($manifest['thumbnail'] ?? null);
        $label = $this->extractLabel($manifest['label'] ?? null);
        $canvases = $manifest['sequences'][0]['canvases'] ?? [];
        if (!is_array($canvases)) {
            return [];
        }

        $rows = [];
        foreach ($canvases as $canvas) {
            if (!is_array($canvas)) {
                continue;
            }
            $images = $canvas['images'] ?? [];
            if (!is_array($images)) {
                continue;
            }
            foreach ($images as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $resource = $image['resource'] ?? null;
                if (!is_array($resource)) {
                    continue;
                }
                $resourceUrl = $this->extractUrl($resource);
                if (!is_string($resourceUrl) || $resourceUrl === '') {
                    continue;
                }
                $iiifBase = $this->extractUrl($resource['service'] ?? null);

                $context = [
                    'iiif_manifest' => $manifestUrl,
                    'record_key' => $manifestId,
                ];
                if (is_string($iiifBase) && $iiifBase !== '') {
                    $context['iiif_base'] = $iiifBase;
                }
                if (is_string($thumbnailUrl) && $thumbnailUrl !== '') {
                    $context['thumbnail_url'] = $thumbnailUrl;
                }
                if (is_string($label) && $label !== '') {
                    $context['dcterms:title'] = $label;
                }

                $rows[] = ['original_url' => $resourceUrl, 'context' => $context];
            }
        }

        return $rows;
    }

    private function extractUrl(mixed $node): ?string
    {
        if (is_string($node) && $node !== '') {
            return $node;
        }
        if (!is_array($node)) {
            return null;
        }
        foreach (['@id', 'id', 'url', 'href', 'src'] as $key) {
            $value = $node[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        foreach ($node as $value) {
            $url = $this->extractUrl($value);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function extractLabel(mixed $label): ?string
    {
        if (is_string($label) && $label !== '') {
            return $label;
        }
        if (!is_array($label)) {
            return null;
        }
        foreach (['none', 'en'] as $lang) {
            $value = $label[$lang] ?? null;
            if (is_array($value) && isset($value[0]) && is_string($value[0]) && $value[0] !== '') {
                return $value[0];
            }
        }

        return null;
    }
}
