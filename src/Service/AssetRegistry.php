<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\MediaRecord;
use App\Repository\AssetRepository;
use App\Repository\MediaRecordRepository;
use App\Workflow\AssetFlow;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Workflow\WorkflowInterface;

final class AssetRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AssetRepository $assetRepository,
        private readonly MediaRecordRepository $mediaRecordRepository,
        private AsyncQueueLocator $asyncQueueLocator,
        #[Target(AssetFlow::WORKFLOW_NAME)] private WorkflowInterface $assetWorkflow,
        private MessageBusInterface $messageBus,
        #[Autowire('%env(S3_ENDPOINT)%')] private readonly string $s3Endpoint,
        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')] private readonly string $s3Bucket,

        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')]
        private readonly string        $archiveBucket,
        private readonly ImgproxyUrlBuilder $imgproxyUrlBuilder,
        private readonly MediaUrlGenerator $mediaUrlGenerator,

    ) {
    }

    /**
     * @param array<string,mixed> $contextHints Optional metadata from the caller (folder path, dates, etc.)
     */
    public function ensureAsset(string $originalUrl, ?string $client, bool $flush = false, array $contextHints = []): Asset
    {
        if (!$asset = $this->assetRepository->findOneByUrl($originalUrl)) {
            $asset = new Asset($originalUrl);
            $this->entityManager->persist($asset);
        }

        // Track which clients submitted this URL
        if ($client !== null && !in_array($client, $asset->clients, true)) {
            $asset->clients[] = $client;
        }

        // Merge source metadata from the caller into sourceMeta (non-destructive).
        // IIIF manifest attachment is intentionally deferred to async workflow.
        if ($contextHints !== []) {
            $asset->sourceMeta ??= [];
            foreach ($contextHints as $key => $value) {
                if (!isset($asset->sourceMeta[$key])) {
                    $asset->sourceMeta[$key] = $value;
                }
            }
        }

        $this->attachMediaRecord($asset, $contextHints, $originalUrl);

        $flush && $this->flush();

        return $asset;
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function dispatch(Asset $asset): void
    {
        // trigger download
        $nextTransition = AssetFlow::TRANSITION_FETCH_IIIF;
        if ($this->assetWorkflow->can($asset, $nextTransition))
        {
            // dispatch a download request
            $message = new TransitionMessage($asset->id,
                $asset::class,
                $nextTransition,
                AssetFlow::WORKFLOW_NAME);
            $stamps = $this->asyncQueueLocator->stamps($message);
            $this->messageBus->dispatch(
                $message,
                $stamps
            );
        }


    }

    public function s3Url(Asset $asset)
    {
        return sprintf("%s/%s/%s", $this->s3Endpoint, $this->s3Bucket, $asset->storageKey);
    }

    /**
     * s3:// source URL for imgproxy (IMGPROXY_USE_S3=true). Distinct from
     * s3Url(), which is the public HTTP URL for browsers. imgproxy fetches the
     * master directly from our bucket via its own S3 client — no external GET,
     * no nesting.
     */
    public function s3SourceUrl(Asset $asset): string
    {
        return sprintf('s3://%s/%s', $this->s3Bucket, $asset->storageKey);
    }

    /** @param array<string,mixed> $contextHints */
    private function attachMediaRecord(Asset $asset, array $contextHints, string $originalUrl): void
    {
        $recordKey = $this->deriveMediaRecordKey($contextHints, $originalUrl);
        if ($recordKey === null) {
            return;
        }

        $record = $this->mediaRecordRepository->findOneByRecordKey($recordKey);
        if (!$record instanceof MediaRecord) {
            $record = new MediaRecord($recordKey);
            $this->entityManager->persist($record);
        }

        if ($asset->mediaRecord?->id !== $record->id) {
            $record->addAsset($asset);
        }

        $record->sourceMeta ??= [];
        $record->sourceMeta['first_asset_id'] ??= $asset->id;
        $record->sourceMeta['page_count'] = $record->childCount;

        $filename = $this->filenameFromUrl($originalUrl);
        if (is_string($filename) && $filename !== '') {
            $record->sourceMeta['filename'] ??= $filename;
            $record->sourceMeta['extension'] ??= $this->extensionFromFilename($filename);
        }

        if ($record->label === null) {
            $label = $contextHints['dcterms:title']
                ?? $contextHints['title']
                ?? null;
            if (is_string($label) && trim($label) !== '') {
                $record->label = trim($label);
            }
        }

        if ($record->sourceMeta === null && $contextHints !== []) {
            $record->sourceMeta = $contextHints;
        } elseif ($contextHints !== []) {
            foreach ($contextHints as $key => $value) {
                if (!isset($record->sourceMeta[$key])) {
                    $record->sourceMeta[$key] = $value;
                }
            }
        }

        $record->sourceUrl ??= $originalUrl;

        if ($this->looksLikePdfUrl($originalUrl)) {
            $record->sourceUrl ??= $originalUrl;
            $record->sourceMime ??= 'application/pdf';
        }

        if ($record->sourceMime === null && is_string($asset->mime) && $asset->mime !== '') {
            $record->sourceMime = $asset->mime;
        }
    }

    /** @param array<string,mixed> $contextHints */
    private function deriveMediaRecordKey(array $contextHints, string $originalUrl): ?string
    {
        $explicitRecordKey = $contextHints['media_record_key'] ?? null;
        $isPdf = $this->looksLikePdfUrl($originalUrl);

        // Current policy: auto-create MediaRecord only for PDFs.
        // Non-PDF grouping requires explicit media_record_key from caller.
        if (!$isPdf && (!is_string($explicitRecordKey) || trim($explicitRecordKey) === '')) {
            return null;
        }

        foreach (['media_record_key', 'record_key', 'source_ark', 'code', 'dcterms:identifier', 'identifier'] as $key) {
            $value = $contextHints[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->normalizeRecordKey($value);
            }
        }

        $iiifManifest = $contextHints['iiif_manifest'] ?? $contextHints['iiifManifest'] ?? null;
        if (is_string($iiifManifest) && trim($iiifManifest) !== '') {
            $base = preg_replace('{/manifest/?$}i', '', trim($iiifManifest));
            if (is_string($base) && $base !== '') {
                return $this->normalizeRecordKey($base);
            }
        }

        if ($isPdf) {
            $stem = $this->recordStemFromUrl($originalUrl);
            return $stem !== null ? $this->normalizeRecordKey('urlstem:' . $stem) : null;
        }

        return null;
    }

    private function normalizeRecordKey(string $raw): ?string
    {
        $normalized = strtolower(trim($raw));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        return substr($normalized, 0, 191);
    }

    private function recordStemFromUrl(string $url): ?string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return null;
        }

        $stem = preg_replace('/\.[a-z0-9]{2,8}$/i', '', $path);
        if (!is_string($stem) || $stem === '') {
            return null;
        }

        $stem = preg_replace('/(?:[_-](?:p|page)?)\d{1,4}$/i', '', $stem) ?? $stem;
        return $host !== '' ? $host . $stem : $stem;
    }

    private function looksLikePdfUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function filenameFromUrl(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $filename = basename($path);

        return $filename !== '' ? $filename : null;
    }

    private function extensionFromFilename(string $filename): ?string
    {
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }

    public function imgProxyUrl(Asset $asset, string $preset = MediaUrlGenerator::PRESET_SMALL): ?string
    {
        return $this->imgProxyDebug($asset, $preset)['url'];
    }

    /**
     * Translate media-bundle preset names (small/medium/large/ai) to the
     * imgproxy-bundle vocabulary (tiny/thumb/observe/display/archive). The two
     * only overlap on "thumb"; without this a media preset like "small" reaches
     * resizePreset() and throws "Unknown imgproxy preset".
     */
    private const IMGPROXY_PRESET_ALIASES = [
        'small'  => 'thumb',
        'medium' => 'display',
        'large'  => 'archive',
        'ai'     => 'observe',
    ];

    /** @return array{url: ?string, source: string, source_url: ?string} */
    public function imgProxyDebug(Asset $asset, string $preset = MediaUrlGenerator::PRESET_SMALL): array
    {
        // if the asset has been stored on OUR s3, then use it, much faster.
        if ($asset->storageKey) {
            // imgproxy reads our master directly from museado (IMGPROXY_USE_S3).
            $source = $this->s3SourceUrl($asset);
            $sourceLabel = 's3_source';
        } elseif (is_string($asset->archiveUrl) && $asset->archiveUrl !== '') {
            $source = $asset->archiveUrl;
            $sourceLabel = 'archive_url';
        } else {
            $source = $asset->originalUrl;
            $sourceLabel = 'original_url';
        }

        // Never derive a preset from another imgproxy URL — that double-encodes
        // (imgproxy fetching imgproxy). Fall back to the original master.
        $imgproxyHost = rtrim((string) $this->imgproxyUrlBuilder->host, '/');
        if ($imgproxyHost !== '' && is_string($source) && str_starts_with($source, $imgproxyHost)) {
            $source = $asset->originalUrl;
            $sourceLabel = 'original_url';
        }

        $preset = self::IMGPROXY_PRESET_ALIASES[$preset] ?? $preset;
        if (!$this->imgproxyUrlBuilder->hasPreset($preset)) {
            $preset = 'thumb';
        }
        $imgproxyUrl = $this->imgproxyUrlBuilder->resizePreset($source, $preset);
        return [
            'url' => $imgproxyUrl,
            'source' => $sourceLabel,
            'source_url' => $source,
        ];

    }

    public function imgProxyUrlWithCrop(
        Asset $asset,
        ?array $crop,
        ?int $resizeW,
        ?int $resizeH,
        bool $bestFit = false,
        ?string $effect = null
    ): string {
        $parts = [];

        if ($crop !== null) {
            [$x, $y, $w, $h] = $crop;
            $parts[] = sprintf('cr:%d:%d:nowe:%s:%s', $w, $h, rtrim(number_format((float) $x, 4, '.', ''), '0'), rtrim(number_format((float) $y, 4, '.', ''), '0'));
        }

        if ($resizeW || $resizeH) {
            $parts[] = sprintf('rs:%s:%d:%d:0', $bestFit ? 'fit' : 'fill', $resizeW ?? 0, $resizeH ?? 0);
        }

        if ($effect === 'grayscale') {
            $parts[] = 'mc:1';
        }

        if ($asset->storageKey) {
            $source = $this->s3Url($asset);
        } elseif (is_string($asset->archiveUrl) && $asset->archiveUrl !== '') {
            $source = $asset->archiveUrl;
        } else {
            $source = $asset->originalUrl;
        }

        return $this->imgproxyUrlBuilder->buildUrl($source, implode('/', $parts) ?: 'rs:fit:0:0:0');
    }
}
