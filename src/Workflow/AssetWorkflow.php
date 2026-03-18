<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Ai\AssetAiTask;
use App\Ai\Result\MediaEnrichment;
use App\Service\AssetRegistry;
use \RuntimeException as RuntimeException;
use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Entity\Variant;
use App\Repository\AssetPathRepository;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use App\Service\ArchiveService;
use App\Service\AtomicFileWriter;
use App\Service\AssetPreviewService;
use App\Service\VariantPlan;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Psr\Log\LoggerInterface;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Survos\MediaBundle\Service\MediaKeyService;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use App\Util\ImageProbe;
use Survos\StateBundle\Attribute\Workflow;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StorageBundle\Service\StorageService;
use Survos\ThumbHashBundle\Service\Thumbhash;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment as TwigEnvironment;
use Survos\GoogleSheetsBundle\Service\GoogleDriveService;
use App\Service\OcrService;
use App\Workflow\AssetFlow as WF;
use App\Workflow\VariantFlowDefinition as VWF;

//#[Workflow(name: WF::WORKFLOW_NAME, supports: [Asset::class])]
class AssetWorkflow
{
    const THUMBHASH_PRESET = 'small';
    private ?float $sourceCooldownUntil = null;
    /** @var array<string, AiTaskInterface> */
    private array $aiTaskHandlers = [];

    public function __construct(
        private MediaUrlGenerator $mediaUrlGenerator,
        private readonly ArchiveService $archiveService,
        private ThumbHashService $thumbHashService,
        private readonly AtomicFileWriter $atomicFileWriter,
        private AssetPreviewService $assetPreviewService,
        private MessageBusInterface          $messageBus,
        private EntityManagerInterface                          $em,
        private AssetRepository                                 $assetRepo,
        private AssetPathRepository                             $assetPathRepo,
        private readonly FilesystemOperator                     $localStorage,
        private readonly LoggerInterface                        $logger,
        private readonly HttpClientInterface                    $httpClient,
        private UserRepository                                  $userRepository,
//        #[Target(TWF::WORKFLOW_NAME)] private WorkflowInterface $thumbWorkflow,
        #[Target(VWF::WORKFLOW_NAME)] private WorkflowInterface $variantWorkflow,
        #[Target(WF::WORKFLOW_NAME)] private WorkflowInterface $assetWorkflow,
        private SerializerInterface                            $serializer,
        private NormalizerInterface                            $normalizer,
        #[Autowire('%env(SAIS_API_ENDPOINT)%')]
        private string                                         $apiEndpoint,
        #[Autowire('%env(int:SERVICE_529_COOLDOWN_MS)%')]
        private readonly int                                   $source529CooldownMs,
        #[Autowire('%env(int:SERVICE_HTTP_TIMEOUT_SECONDS)%')]
        private readonly int                                   $serviceHttpTimeoutSeconds,
        #[Autowire('%kernel.project_dir%/public/temp')]
        private string                                         $tempDir,
        private readonly EntityManagerInterface                $entityManager,
        private readonly AsyncQueueLocator                     $asyncQueueLocator,
        private readonly VariantPlan                           $plan,
        private readonly StorageService $storageService,
        private readonly AssetRegistry $assetRegistry,
        private readonly OcrService $ocrService,
        private readonly TwigEnvironment $twig,
        private readonly ?FilesystemOperator $archiveStorage = null,
        private ?GoogleDriveService $driveService = null,
        iterable $taskServices = [],
    ) {
        foreach ($taskServices as $task) {
            if ($task instanceof AiTaskInterface) {
                $this->aiTaskHandlers[$task->getTask()] = $task;
            }
        }
    }

    /** @return Asset */
    private function getAsset(Event $event): Asset
    {
        //        assert($subject instanceof Asset, 'Expected Asset entity, got ' . get_class($subject));
        return $event->getSubject();
    }

    private function uploadToArchiveFromPath(Asset $asset, string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException(sprintf(
                'Local payload missing for archive (asset %s, path "%s").',
                $asset->id,
                $localPath
            ));
        }

        $localSize = filesize($localPath);
        if ($localSize === 0) {
            throw new RuntimeException(sprintf(
                'Refusing to archive zero-byte payload (asset %s).',
                $asset->id
            ));
        }

        // Derive extension from detected MIME
        $extension = MediaKeyService::extensionFromMime($asset->mime) ?? ($asset->ext ?: 'bin');

        // Prefer content-addressed path when available, fallback to URL-based path.
        $sha256 = $asset->context['sha256'] ?? null;
        if (is_string($sha256) && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
            $path = sprintf('orig/%s/%s/%s.%s', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256, $extension);
        } else {
            $path = MediaKeyService::archivePathFromUrl(
                $asset->originalUrl,
                $extension
            );
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to open local payload for streaming.');
        }

        try {
            // Idempotency: if object already exists, verify integrity
            if ($this->archiveStorage->fileExists($path)) {
                $remoteSize = $this->archiveStorage->fileSize($path);
                if ($remoteSize !== $localSize) {
                    throw new RuntimeException(sprintf(
                        'Archive object exists with mismatched size (remote=%d, local=%d).',
                        $remoteSize,
                        $localSize
                    ));
                }
            } else {
                // Stream upload (constant memory)
                $this->archiveStorage->writeStream(
                    $path,
                    $stream,
                    ['visibility' => Visibility::PUBLIC]
                );
            }
        } finally {
            fclose($stream);
        }

        // Persist archive metadata
        $asset->storageKey = $path;
        $asset->storageBackend = 'archive';
        $asset->archiveUrl = $this->assetRegistry->s3Url($asset);
        $asset->smallUrl = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
    }

    public function ingestLocalFile(Asset $asset, string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException(sprintf('Local file not found: %s', $localPath));
        }

        $asset->statusCode = 200;
        $this->processLocalFile($localPath, $asset);
        $detectedExt = ImageProbe::extFromMime($asset->mime);
        if ($detectedExt) {
            $asset->ext = $detectedExt;
        }

        $this->uploadToArchiveFromPath($asset, $localPath);
        $this->em->flush();
    }

//    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_ARCHIVE)]
    public function onArchive(TransitionEvent $event): void
    {
        return; // no-op, everything now in download.
        $asset = $this->getAsset($event);


        // if archived we don't need the temp file.

        $asset->storageBackend = 'archive';
        $asset->storageKey = $path;
        // only AFTER we have a storage key, so it uses the s3 image for the thumb
        $asset->smallUrl = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
         if ($asset->tempFilename) {
             // keep files during testing locally
//             unlink($asset->tempFilename);
         }
         $asset->tempFilename = null;

         // Archive marks the end of the asset lifecycle for this worker
         $this->em->flush();
//         $this->em->detach($asset);
         gc_collect_cycles();

    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_AI_TASK)]
    public function onAiTask(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        $this->runNextAiTask($asset);
        $this->em->flush();
    }

        /**
     * @throws FilesystemException
     * @throws TransportExceptionInterface
     */
    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_DOWNLOAD)]
    public function onDownload(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        $url = $asset->originalUrl;

//        $url = 'https://ciim-public-media-s3.s3.eu-west-2.amazonaws.com/ramm/41_2005_3_2.jpg';
//        $url = 'https://coleccion.museolarco.org/public/uploads/ML038975/ML038975a_1733785969.webp';
//        $asset->setOriginalUrl($url);
        // we use the original extension

        $uri = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($uri, PATHINFO_EXTENSION);

        if (empty($ext)) {
            $ext = 'tmp'; // Will be corrected after download based on actual mime type
        }
        $asset->ext = $ext;

        $key = $this->archiveService->keyForUrl($asset->originalUrl);
        $path = basename($this->archiveService->payloadPath($key, $ext));

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        assert($path, "Missing $path");
        $tempFile = $this->tempDir . '/' . str_replace('/', '-', $path);
        $asset->statusCode = 200;
        // path will change if there is an extension mismatch!
        // Download to a process-local temp file (not persisted)
        $tmpFile = tempnam(sys_get_temp_dir(), 'asset_');
        try {
            $this->downloadUrl($url, $tmpFile);
            if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
                throw new RuntimeException(sprintf('Downloaded zero-byte payload for asset %s', $asset->id));
            }

            // no network calls! Only what we need while we have local
            // Inspect local file once: size, mime, dimensions, exif (when applicable)
            $this->processLocalFile($tmpFile, $asset);

            // Normalize extension based on detected mime type
            $detectedExt = ImageProbe::extFromMime($asset->mime);
            $currentExt = pathinfo($tmpFile, PATHINFO_EXTENSION);
            if ($detectedExt && $currentExt !== $detectedExt) {
                $renamed = $tmpFile . '.' . $detectedExt;
                rename($tmpFile, $renamed);
                $tmpFile = $renamed;
                $asset->ext = $detectedExt;
            }

            // tasks[] controls which analysis steps to run for this asset.
            // Sent by ssai in context hints; defaults to all tasks if absent.
            $tasks = $asset->context['tasks'] ?? ['thumbhash', 'palette'];

            // OCR — while the file is local, no second download needed
            if (str_starts_with((string) $asset->mime, 'image/')) {
                // Thumbhash — resize to ≤100px before extracting pixels (thumbhash max is 192x192)
                if (in_array('thumbhash', $tasks, true)) {
                    try {
                        $img = new \Imagick($tmpFile);
                        $img->thumbnailImage(100, 100, bestfit: true);
                        $tw = $img->getImageWidth();
                        $th = $img->getImageHeight();
                        $pixels = [];
                        $iter = $img->getPixelIterator();
                        foreach ($iter as $row) {
                            foreach ($row as $pixel) {
                                $c = $pixel->getColor(2);
                                $pixels[] = $c['r'];
                                $pixels[] = $c['g'];
                                $pixels[] = $c['b'];
                                $pixels[] = $c['a'];
                            }
                        }
                        $img->clear();
                        $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
                        $asset->context['thumbhash'] = Thumbhash::convertHashToString($hash);
                        unset($pixels, $iter, $img);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Thumbhash failed for {id}: {err}', ['id' => $asset->id, 'err' => $e->getMessage()]);
                    }
                }

                if (in_array('palette', $tasks, true)) {
                    $this->assetPreviewService->maybeComputePaletteAndPhash($asset, self::THUMBHASH_PRESET, $tmpFile);
                }
            }

            // Archive to S3 — now after all local analysis is done
            $this->uploadToArchiveFromPath($asset, $tmpFile);
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }

        // now we can save everything and move to the next step.
        $this->em->flush();
    }


    /**
     *
     * Because onCompleted happens BEFORE the specific transition onCompleted,
     * we need to match this event exactly.  Otherwise, the next events are dispatched too soon.
     */
    // #[AsCompletedListener(WF::TRANSITION_ARCHIVE, priority: 1000)] NOT THIS!  See note above
    #[AsCompletedListener(WF::WORKFLOW_NAME, priority: 1000)]
    public function onCompleted(CompletedEvent $event): void
    {
        $asset = $this->getAsset($event);
        $this->em->flush();

        $transitionName = $event->getTransition()->getName();
        if (in_array($transitionName, [WF::TRANSITION_QUEUE_AI, WF::TRANSITION_AI_TASK], true) && !$asset->aiLocked) {
            if (!empty($asset->aiQueue) && $this->assetWorkflow->can($asset, WF::TRANSITION_AI_TASK)) {
                $this->dispatchTransition($asset, WF::TRANSITION_AI_TASK);
            } elseif (empty($asset->aiQueue) && $this->assetWorkflow->can($asset, WF::TRANSITION_AI_DONE)) {
                $this->dispatchTransition($asset, WF::TRANSITION_AI_DONE);
            }
        }

        // Fire webhook back to any registered callback URL once analysis is done
        $callbackUrl = $asset->context['callback_url'] ?? null;
        if ($callbackUrl && $asset->marking === WF::PLACE_ANALYZED) {
            $this->fireWebhook($asset, (string) $callbackUrl);
        }

        // don't detach until AFTER completed is called, or we won't save the marking.
        $this->em->detach($asset);
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_ANALYZE)]
    public function onLocalAnalyze(TransitionEvent $event): void
    {
        // Analysis now happens AFTER archive using S3-backed URLs
        $asset = $this->getAsset($event);

        if (!$asset->mime || !str_starts_with($asset->mime, 'image/')) {
            $this->logger->info("Skipping analysis for non-image asset ({$asset->mime})");
            return;
        }

        // Thumbhash and palette were computed in onDownload while the file was local.
        // Only fall back to the archive URL fetch if they're missing (e.g. older assets).
        $asset->context ??= [];
        $tasks = $asset->context['tasks'] ?? ['thumbhash', 'palette'];

        if (in_array('ocr', $tasks, true) && empty($asset->context['ocr'])) {
            $ocrSourceUrl = $asset->smallUrl ?? $asset->archiveUrl ?? null;
            if ($ocrSourceUrl) {
                $ocrTmp = tempnam(sys_get_temp_dir(), 'asset_ocr_');
                if ($ocrTmp !== false) {
                    try {
                        $this->downloadUrl($ocrSourceUrl, $ocrTmp);
                        $ocrText = $this->ocrService->extractText($ocrTmp, $asset->mime);
                        if ($ocrText !== null && $ocrText !== '') {
                            $asset->context['ocr'] = $ocrText;
                            $asset->context['ocr_chars'] = mb_strlen($ocrText);
                        }
                    } finally {
                        if (is_file($ocrTmp)) {
                            unlink($ocrTmp);
                        }
                    }
                }
            }
        }

        if (empty($asset->context['thumbhash']) && $asset->archiveUrl) {
            $this->logger->info('onLocalAnalyze: thumbhash missing, fetching from archive URL (fallback)');
            [$tw, $th, $pixels] = $this->assetPreviewService->resizeForThumbHashFromUrl($asset->archiveUrl);
            $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
            $asset->context['thumbhash'] = Thumbhash::convertHashToString($hash);
            unset($pixels);
        }

        if (empty($asset->context['colors']) && $asset->archiveUrl) {
            $this->assetPreviewService->maybeComputePaletteAndPhash(
                $asset,
                self::THUMBHASH_PRESET,
                $asset->archiveUrl
            );
        }

        $this->em->flush();
//        $this->em->detach($asset);
    }

    /**
     * Runs exactly one pending AI task from aiQueue.
     *
     * @return string|null Task name that ran, or null when skipped.
     */
    public function runNextAiTask(Asset $asset, bool $completeWhenQueueEmpty = false): ?string
    {
        if ($asset->aiLocked) {
            $this->logger->info('Asset AI pipeline locked for {id}; skipping.', ['id' => $asset->id]);
            return null;
        }

        if (empty($asset->aiQueue)) {
            if ($completeWhenQueueEmpty) {
                $this->finishAiPipeline($asset);
            }
            return null;
        }

        $taskName = (string) array_shift($asset->aiQueue);
        $handler = $this->aiTaskHandlers[$taskName] ?? null;

        if (!$handler instanceof AiTaskInterface) {
            $this->logger->warning(
                'Unknown AI task "{task}" on asset {id}; skipping.',
                ['task' => $taskName, 'id' => $asset->id],
            );
            $this->recordCompletedTask($asset, $taskName, ['skipped' => true, 'reason' => 'task handler not found']);
            if ($completeWhenQueueEmpty && empty($asset->aiQueue)) {
                $this->finishAiPipeline($asset);
            }
            return $taskName;
        }

        $taskOverride = $this->consumeTaskOverride($asset, $taskName);
        ['inputs' => $inputs, 'imageSource' => $imageSource] = $this->buildAiInputs($asset, $taskName, $taskOverride);
        if (isset($taskOverride['model']) && is_string($taskOverride['model']) && $taskOverride['model'] !== '') {
            $inputs['model_hint'] = $taskOverride['model'];
        }
        if (!$handler->supports($inputs, $asset->context ?? [])) {
            $this->recordCompletedTask($asset, $taskName, ['skipped' => true, 'reason' => 'not supported for this asset']);
            if ($completeWhenQueueEmpty && empty($asset->aiQueue)) {
                $this->finishAiPipeline($asset);
            }
            return $taskName;
        }

        $priorResults = $this->indexedPriorResults($asset);

        try {
            $result = $handler->run($inputs, $priorResults, $asset->context ?? []);
            $result = $this->attachDebugContext($asset, $taskName, $handler, $inputs, $priorResults, $result, $imageSource, $taskOverride);
            $this->recordCompletedTask($asset, $taskName, $result);
        } catch (\Throwable $e) {
            $this->logger->error(
                'AI task "{task}" failed on asset {id}: {error}',
                ['task' => $taskName, 'id' => $asset->id, 'error' => $e->getMessage()],
            );
            $this->recordCompletedTask($asset, $taskName, ['failed' => true, 'error' => $e->getMessage()]);
        }

        if ($completeWhenQueueEmpty && empty($asset->aiQueue)) {
            $this->finishAiPipeline($asset);
        }

        return $taskName;
    }

    private function dispatchTransition(Asset $asset, string $transition): void
    {
        $msg = new TransitionMessage($asset->id, Asset::class, $transition, WF::WORKFLOW_NAME);
        $this->messageBus->dispatch($msg, $this->asyncQueueLocator->stamps($msg));
    }

    /** @return array<string, mixed> */
    /**
     * @return array{inputs: array<string,mixed>, imageSource: string}
     */
    private function buildAiInputs(Asset $asset, string $taskName, array $taskOverride = []): array
    {
        ['url' => $imageUrl, 'source' => $imageSource] = $this->selectTaskImageUrl($asset, $taskName, $taskOverride);

        $inputs = array_filter([
            'image_url' => $imageUrl,
            'mime' => $asset->mime ?? null,
        ], static fn ($value) => $value !== null);

        return [
            'inputs' => $inputs,
            'imageSource' => $imageSource,
        ];
    }

    /** @return array{url: ?string, source: string} */
    private function selectTaskImageUrl(Asset $asset, string $taskName, array $taskOverride = []): array
    {
        $imagePreference = $taskOverride['image_source_preference'] ?? null;
        if (is_string($imagePreference)) {
            if ($imagePreference === 'full') {
                return $this->preferredFullImageUrl($asset);
            }

            if (in_array($imagePreference, ['thumb', 'thumbnail', 'small'], true)) {
                $thumb = $this->preferredThumbnailUrl($asset);
                return $thumb['url'] !== null ? $thumb : $this->preferredFullImageUrl($asset);
            }
        }

        if ($taskName === AssetAiTask::TRANSCRIBE_HANDWRITING->value) {
            return $this->preferredFullImageUrl($asset);
        }

        if ($taskName === AssetAiTask::ENRICH_FROM_THUMBNAIL->value) {
            $thumb = $this->preferredThumbnailUrl($asset);
            if ($thumb['url'] !== null) {
                return $thumb;
            }

            return $this->preferredFullImageUrl($asset);
        }

        return $this->preferredFullImageUrl($asset);
    }

    /** @return array{url: ?string, source: string} */
    private function preferredFullImageUrl(Asset $asset): array
    {
        $iiifFullUrl = $this->iiifFullUrl($asset);
        if ($iiifFullUrl !== null) {
            return ['url' => $iiifFullUrl, 'source' => 'iiif_full'];
        }

        $archiveUrl = $this->effectiveArchiveUrl($asset);
        if ($archiveUrl !== null) {
            return ['url' => $archiveUrl, 'source' => 'archive_url'];
        }

        if ($asset->originalUrl !== '') {
            return ['url' => $asset->originalUrl, 'source' => 'original_url'];
        }

        if ($asset->smallUrl !== null && $asset->smallUrl !== '') {
            return ['url' => $asset->smallUrl, 'source' => 'small_url'];
        }

        return ['url' => null, 'source' => 'none'];
    }

    /** @return array{url: ?string, source: string} */
    private function preferredThumbnailUrl(Asset $asset): array
    {
        $sourceMeta = $asset->sourceMeta ?? [];

        $candidates = [
            'source_meta.thumbnail_url' => $sourceMeta['thumbnail_url'] ?? null,
            'source_meta.thumb_url' => $sourceMeta['thumb_url'] ?? null,
            'source_meta.iiif_thumbnail_url' => $sourceMeta['iiif_thumbnail_url'] ?? null,
            'source_meta.small_url' => $sourceMeta['small_url'] ?? null,
            'source_meta.preview_url' => $sourceMeta['preview_url'] ?? null,
            'source_meta.thumbnail' => $sourceMeta['thumbnail'] ?? null,
            'source_meta.thumb' => $sourceMeta['thumb'] ?? null,
        ];

        foreach ($candidates as $source => $candidate) {
            $url = $this->extractUrlFromMixed($candidate);
            if ($url !== null) {
                return ['url' => $url, 'source' => $source];
            }
        }

        $iiifThumb = $this->iiifThumbnailUrl($asset);
        if ($iiifThumb !== null) {
            return ['url' => $iiifThumb, 'source' => 'iiif_thumbnail'];
        }

        if ($asset->smallUrl !== null && $asset->smallUrl !== '') {
            return ['url' => $asset->smallUrl, 'source' => 'small_url'];
        }

        return [
            'url' => $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL),
            'source' => 'imgproxy_small',
        ];
    }

    private function iiifThumbnailUrl(Asset $asset): ?string
    {
        $iiifBase = $asset->sourceMeta['iiif_base'] ?? null;
        if (!is_string($iiifBase) || $iiifBase === '') {
            return null;
        }

        return rtrim($iiifBase, '/') . '/full/!512,512/0/default.jpg';
    }

    private function extractUrlFromMixed(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->isLikelyUrl($value) ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['url', 'id', '@id', 'href', 'src'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $url = $this->extractUrlFromMixed($value[$key]);
            if ($url !== null) {
                return $url;
            }
        }

        foreach ($value as $item) {
            $url = $this->extractUrlFromMixed($item);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function isLikelyUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (bool) filter_var($value, FILTER_VALIDATE_URL);
    }

    /** @return array<string,mixed> */
    private function consumeTaskOverride(Asset $asset, string $taskName): array
    {
        $context = $asset->context ?? [];
        $overrides = $context['ai_task_overrides'] ?? null;
        if (!is_array($overrides) || !isset($overrides[$taskName]) || !is_array($overrides[$taskName])) {
            return [];
        }

        $override = $overrides[$taskName];
        unset($overrides[$taskName]);
        $context['ai_task_overrides'] = $overrides;
        $asset->context = $context;

        return $override;
    }

    private function recordCompletedTask(Asset $asset, string $taskName, array $result): void
    {
        $asset->aiCompleted[] = [
            'task' => $taskName,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'result' => $result,
        ];

        if ($taskName === AssetAiTask::CLASSIFY->value
            && empty($result['failed'])
            && empty($result['skipped'])
        ) {
            $asset->aiDocumentType = $result['type'] ?? null;
        }

        $enrichment = MediaEnrichment::fromCompleted($asset->aiCompleted);
        $asset->mediaEnrichment = $enrichment->toArray();
        $asset->enriched = $enrichment;
    }

    private function finishAiPipeline(Asset $asset): void
    {
        if ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_DONE)) {
            $this->assetWorkflow->apply($asset, WF::TRANSITION_AI_DONE);
        }
    }

    /** @return array<string, array> */
    private function indexedPriorResults(Asset $asset): array
    {
        $index = [];
        foreach ($asset->aiCompleted as $entry) {
            if (isset($entry['task'], $entry['result']) && is_array($entry['result'])) {
                $index[(string) $entry['task']] = $this->sanitisePriorResult($entry['result']);
            }
        }

        return $index;
    }

    private function sanitisePriorResult(array $result): array
    {
        unset($result['raw_response'], $result['blocks']);

        if (isset($result['text']) && is_string($result['text']) && mb_strlen($result['text']) > 8000) {
            $result['text'] = mb_substr($result['text'], 0, 8000) . "\n[… truncated for context]";
        }

        return $result;
    }

    private function iiifFullUrl(Asset $asset): ?string
    {
        $iiifBase = $asset->sourceMeta['iiif_base'] ?? null;
        if (!is_string($iiifBase) || $iiifBase === '') {
            return null;
        }

        return rtrim($iiifBase, '/') . '/full/max/0/default.jpg';
    }

    private function effectiveArchiveUrl(Asset $asset): ?string
    {
        if (!$asset->storageKey) {
            return $asset->archiveUrl;
        }

        $computed = $this->assetRegistry->s3Url($asset);
        if ($asset->archiveUrl !== $computed) {
            $this->logger->warning('Asset {id} has stale archiveUrl; using computed URL.', [
                'id' => $asset->id,
                'stored_archive_url' => $asset->archiveUrl,
                'computed_archive_url' => $computed,
            ]);
            $asset->archiveUrl = $computed;
        }

        return $computed;
    }

    private function attachDebugContext(
        Asset $asset,
        string $taskName,
        AiTaskInterface $handler,
        array $inputs,
        array $priorResults,
        array $result,
        string $imageSource,
        array $taskOverride = [],
    ): array {
        $storedArchiveUrl = $asset->archiveUrl;
        $archiveUrl = $this->effectiveArchiveUrl($asset);

        $debug = [
            'asset_id' => $asset->id,
            'task' => $taskName,
            'image_url' => $inputs['image_url'] ?? null,
            'image_source' => $imageSource,
            'requested_image_preference' => $taskOverride['image_source_preference'] ?? null,
            'requested_model' => $taskOverride['model'] ?? null,
            'tokens' => $result['_tokens'] ?? null,
            'image_candidates' => [
                'preferred_thumbnail_url' => $this->preferredThumbnailUrl($asset)['url'],
                'iiif_thumbnail_url' => $this->iiifThumbnailUrl($asset),
                'iiif_full_url' => $this->iiifFullUrl($asset),
                'small_url' => $asset->smallUrl,
                'archive_url' => $archiveUrl,
                'stored_archive_url' => $storedArchiveUrl,
                'original_url' => $asset->originalUrl,
            ],
            'iiif_manifest' => $asset->sourceMeta['iiif_manifest'] ?? null,
            'iiif_base' => $asset->sourceMeta['iiif_base'] ?? null,
            'meta' => $handler->getMeta(),
        ];

        $prompt = $this->renderPromptDebug($taskName, $inputs, $priorResults, $asset->context ?? []);
        if ($prompt !== null) {
            $debug['prompt'] = $prompt;
        }

        $result['_debug'] = $debug;

        return $result;
    }

    /** @return array{system:string,user:string,combined:string}|null */
    private function renderPromptDebug(string $taskName, array $inputs, array $priorResults, array $context): ?array
    {
        $template = "@SurvosAiPipeline/prompt/{$taskName}";
        $templateContext = [
            'imageUrl' => $inputs['image_url'] ?? null,
            'inputs' => $inputs,
            'context' => $context,
            'prior' => $priorResults,
            'ocr_text' => $priorResults['ocr_mistral']['text'] ?? $priorResults['ocr']['text'] ?? null,
            'type' => $priorResults['classify']['type'] ?? null,
            'metadata' => $priorResults['extract_metadata'] ?? [],
            'description' => $priorResults['context_description']['description']
                ?? $priorResults['basic_description']['description']
                ?? null,
            'title' => $priorResults['generate_title']['title'] ?? null,
        ];

        try {
            $systemPrompt = trim($this->twig->render("{$template}/system.html.twig", $templateContext));
            $userPrompt = trim($this->twig->render("{$template}/user.html.twig", $templateContext));

            return [
                'system' => $systemPrompt,
                'user' => $userPrompt,
                'combined' => trim("[SYSTEM]\n{$systemPrompt}\n\n[USER]\n{$userPrompt}"),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * writes the URL locally, esp during debugging but also to check the mime type
     *
     * @param string $url
     * @param string $tempFile
     * @return void
     * @throws TransportExceptionInterface
     */
    private function downloadUrl(string $url, string $tempFile): string
    {
        // if it already exists
        if (file_exists($tempFile) && filesize($tempFile) > 0) {
            return $tempFile;
        }

        if (str_contains($url, 'drive.google.com')) {
            $this->driveService->downloadFileFromUrl(
                $url,
                $tempFile
            );
        } else {
            $client = $this->httpClient;
            $maxAttempts = 4;
            $retryableStatus = [429, 503, 529];

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $this->waitForSourceCooldown();

                $response = $client->request('GET', $url, [
                    'timeout' => $this->serviceHttpTimeoutSeconds,
                ]);
                $code = $response->getStatusCode();
                if ($code !== 200) {
                    if (in_array($code, $retryableStatus, true) && $attempt < $maxAttempts) {
                        if ($code === 529 && $this->source529CooldownMs > 0) {
                            $this->sourceCooldownUntil = microtime(true) + ($this->source529CooldownMs / 1000);
                        }

                        $delaySeconds = (float) (2 ** ($attempt - 1));
                        $jitterMicros = random_int(0, 250000);
                        $this->logger->warning('Source fetch backoff for {url}: HTTP {status} (attempt {attempt}/{max})', [
                            'url' => $url,
                            'status' => $code,
                            'attempt' => $attempt,
                            'max' => $maxAttempts,
                            'cooldown_ms' => $code === 529 ? $this->source529CooldownMs : 0,
                        ]);
                        usleep((int) ($delaySeconds * 1_000_000) + $jitterMicros);
                        continue;
                    }

                    // 404/410 = permanent failure — don't retry, mark as dead
                    if (in_array($code, [404, 410], true)) {
                        throw new \Survos\StateBundle\Exception\UnrecoverableMessageException(
                            "Permanent failure (HTTP {$code}) for {$url} — skipping retries"
                        );
                    }
                    throw new \Exception("Problem with $url " . $code, code: $code);
                }

                $fileHandler = fopen($tempFile, 'w');
                foreach ($client->stream($response) as $chunk) {
                    fwrite($fileHandler, $chunk->getContent());
                }
                fclose($fileHandler);
                break;
            }
        }

        $this->logger->warning("Downloaded url: $url as $tempFile " . filesize($tempFile));
        return $tempFile;

    }

    private function waitForSourceCooldown(): void
    {
        if ($this->sourceCooldownUntil === null) {
            return;
        }

        $remaining = $this->sourceCooldownUntil - microtime(true);
        if ($remaining <= 0) {
            $this->sourceCooldownUntil = null;
            return;
        }

        usleep((int) ($remaining * 1_000_000));
        $this->sourceCooldownUntil = null;
    }

    private function processLocalFile(string $localAbsoluteFilename, Asset $asset): array
    {
        $asset->ext = pathinfo($localAbsoluteFilename, PATHINFO_EXTENSION);
        $mimeType = mime_content_type($localAbsoluteFilename); //

        // Compute content hashes once while file is local.
        $asset->contentHash = hash_file('xxh3', $localAbsoluteFilename);
        $sha256 = hash_file('sha256', $localAbsoluteFilename);
        $asset->context ??= [];
        $asset->context['contentHash'] = $asset->contentHash;
        $asset->context['sha256'] = $sha256;
        // Only process image files for dimensions and exif
        if (str_starts_with($mimeType, 'image/')) {
            [$width, $height, $type, $attr] = getimagesize($localAbsoluteFilename, $info);
            if (!$width) {
                // handled in onComplete
                $this->logger->warning("Invalid temp file $localAbsoluteFilename: $mimeType");
            }

            $asset->width = $width;
            $asset->height = $height;
            $asset->mime = $mimeType;
            $asset->size = filesize($localAbsoluteFilename);

            // problems encoding exif
            // Only read exif for supported image types
            // exif_read_data supports jpeg, tiff, and some webp
            if (in_array($asset->ext, ['jpg', 'jpeg', 'tiff', 'webp'])) {
                try {
                    $exif = @exif_read_data($localAbsoluteFilename, 'IFD0,EXIF,COMPUTED', true);

                     if ($exif !== false) {
                         // clean exif data : remove EXIF key
                         $exif = array_filter($exif, fn($key) => !str_starts_with($key, 'EXIF'), ARRAY_FILTER_USE_KEY);
                         // flatten nested arrays but preserve keys
                         $exif = iterator_to_array(
                             new \RecursiveIteratorIterator(
                                 new \RecursiveArrayIterator($exif)
                             ),
                             true // preserve keys
                         );
                         // normalize values to valid UTF-8 to avoid downstream encoding issues
                         $exif = array_map(static function ($value) {
                             if (is_string($value)) {
                                 // Convert invalid byte sequences to UTF-8, replacing invalid chars
                                 return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                             }
                             return $value;
                         }, $exif);

                         $asset->context ??= [];
                         $asset->context['exif'] = $exif;
                     }
                } catch (\Throwable $e) {
                    $this->logger->warning("EXIF read failed for $localAbsoluteFilename: " . $e->getMessage());
                }
            }
        } else {
            // For non-images, just set mime type and size
            $asset->mime = $mimeType;
            $asset->size = filesize($localAbsoluteFilename);
        }

        return [
            'tempFile' => $localAbsoluteFilename,
            'mimeType' => $mimeType,
            'ext' => $asset->ext
        ];

    }

    private function resizeForThumbHash(string $imagePath, int $size = 100): array
    {
        $image = new \Imagick($imagePath);

        // Resize to fit within $size x $size, maintaining aspect ratio
        // it's probably the reason analyze is slow, we _could_ call imgProxy with the file
        // but seems like an optimization for later.  We could move it to after archive, too!
        // but now we have the image locally.
        // imgProxy now runs locally too, so this logic may need rethinking.
        $image->thumbnailImage($size, $size, true);

        // 100x100 is okay, this is a oneoff that's not saved.
        // If you need exactly 192x192 with padding/centering:
        // $image->setImageBackgroundColor('transparent');
        // $image->extentImage($size, $size,
        //     -($size - $image->getImageWidth()) / 2,
        //     -($size - $image->getImageHeight()) / 2
        // );
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {

                $pixel = $image->getImagePixelColor($x, $y);
                $colors = $pixel->getColor(2);
                $pixels[] = $colors['r'];
                $pixels[] = $colors['g'];
                $pixels[] = $colors['b'];
                $pixels[] = $colors['a'];
            }
        }

        return [$width, $height, $pixels];
    }

    private function fireWebhook(Asset $asset, string $callbackUrl): void
    {
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
                'ocr'          => $asset->context['ocr']          ?? null,
                'ocr_chars'    => $asset->context['ocr_chars']    ?? null,
                'thumbhash'    => $asset->context['thumbhash']    ?? null,
                'colors'       => $asset->context['colors']       ?? null,
                'phash'        => $asset->context['phash']        ?? null,
                'path'         => $asset->context['path']         ?? null,
                'tenant'       => $asset->context['tenant']       ?? null,
                'image_id'     => $asset->context['image_id']     ?? null,
            ],
        ];

        try {
            $this->httpClient->request('POST', $callbackUrl, [
                'json'    => $payload,
                'timeout' => 10,
            ]);
            $this->logger->info('Webhook fired to {url} for asset {id}', [
                'url' => $callbackUrl,
                'id'  => $asset->id,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook failed for {id}: {err}', [
                'id'  => $asset->id,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
