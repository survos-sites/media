<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Entity\Media;
use App\Entity\Variant;
use App\Repository\AssetPathRepository;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use App\Service\ApiService;
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
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Survos\SaisBundle\Service\SaisClientService;

use App\Util\ImageProbe;
use Survos\StateBundle\Attribute\Workflow;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Survos\GoogleSheetsBundle\Service\GoogleDriveService;
use App\Workflow\AssetFlow as WF;
use App\Workflow\ThumbFlowDefinition as TWF;
use App\Workflow\VariantFlowDefinition as VWF;

#[Workflow(name: WF::WORKFLOW_NAME, supports: [Asset::class])]
class AssetWorkflow
{
    const THUMBHASH_PRESET = 'small';
    public function __construct(
        private MediaUrlGenerator $mediaUrlGenerator,
        private readonly ArchiveService $archiveService,
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
        private readonly ApiService                             $apiService,
//        #[Target(TWF::WORKFLOW_NAME)] private WorkflowInterface $thumbWorkflow,
        #[Target(VWF::WORKFLOW_NAME)] private WorkflowInterface $variantWorkflow,
        #[Target(WF::WORKFLOW_NAME)] private WorkflowInterface  $assetWorkflow,
        private SerializerInterface                             $serializer,
        private NormalizerInterface                             $normalizer,
        #[Autowire('%env(SAIS_API_ENDPOINT)%')]
        private string                                          $apiEndpoint,
        #[Autowire('%kernel.project_dir%/public/temp')]
        private string                                          $tempDir, private readonly EntityManagerInterface $entityManager, private readonly AsyncQueueLocator $asyncQueueLocator,
        private readonly ?FilesystemOperator                    $defaultStorage = null,
        private ?GoogleDriveService                             $driveService   = null,
        private readonly VariantPlan                            $plan,
        private readonly StorageService                         $storageService,

    ) {
    }

    /** @return Asset */
    private function getAsset(Event $event): Asset
    {
        /** @var Asset $asset */ $asset = $event->getSubject();
        return $asset;
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

        // Check if asset is already fully processed (has resized data, proper status and a file size in bytes)
        if ($asset->resizedCount && $asset->size) {
            $this->logger->info("Asset {$asset->id} already processed, skipping download and processing");
            return;
        }

        $key = $this->archiveService->keyForUrl($asset->originalUrl);
        $path = basename($this->archiveService->payloadPath($key, $ext));

        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        assert($path, "Missing $path");
        $tempFile = $this->tempDir . '/' . str_replace('/', '-', $path);
        $asset->statusCode = 200;
        // path will change if there is an extension mismatch!
        [$path, $absolutePath] = $this->downloadFileToLocalStorage($url, $path);

            $fileData = $this->processLocalFile($absolutePath, $asset);
            try {
            } catch (\Exception $e) {
                $asset->statusCode = $e->getCode();
                return;
            }
            $asset->tempFilename = $absolutePath;
    }

    public function getLocalAbsolutePath(string $filePath): string
    {
        $adapter = $this->storageService->getAdapterModel('local.storage');
        $absolutePath = $adapter->getAbsolutePath($filePath);
        //

        return $absolutePath;
    }

    public function downloadFileToLocalStorage(
        string $url, string $destinationPath): array
    {
        if ($this->localStorage->has($destinationPath)) {
            $absolutePath = $this->getLocalAbsolutePath($destinationPath);
            return [$destinationPath, $absolutePath];
        }
        try {
            $this->logger->warning("Downloading $url to $destinationPath");
            // Create a streaming request
            $response = $this->httpClient->request('GET', $url, [
                'buffer' => false, // This enables streaming
            ]);

            // Check if the request was successful
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to download file: ' . $response->getStatusCode());
            }

            // Get the response body as a stream
            $stream = $response->toStream();

            // Write the stream directly to Flysystem
            $this->localStorage->writeStream($destinationPath, $stream);

            $this->logger->info('File downloaded successfully', [
                'url' => $url,
                'destination' => $destinationPath
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to download file', [
                'url' => $url,
                'destination' => $destinationPath,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
        // with localStorage, we can rename the file and fix the asset if the mime type is wrong.
        $absolutePath = $this->getLocalAbsolutePath($destinationPath);
        $mimeType = mime_content_type($absolutePath);
        $correctExt = ImageProbe::extFromMime($mimeType);
        $extFromFilename = pathinfo($absolutePath, PATHINFO_EXTENSION);

        if ($correctExt <> $extFromFilename) {
            $destinationInfo = pathinfo($destinationPath);
            $destinationPath = $destinationInfo['dirname'] . '/' . $destinationInfo['filename'] . '.' . $correctExt;
            $pathInfo = pathinfo($absolutePath);
            $directory = $pathInfo['dirname'] === '.' ? '' : $pathInfo['dirname'] . '/';
            $newFilePath = $directory . $pathInfo['filename'] . '.' . $correctExt;
            if (file_exists($newFilePath)) {
                unlink($newFilePath);
            }
            rename($absolutePath, $newFilePath);
            $absolutePath = $newFilePath;
        }
        return [$destinationPath, $absolutePath];
    }

    /**
     * After an Asset has been downloaded, create Variant rows for all required presets.
     * The Variant workflow will auto-advance (Place(next: resize)) and run resizing async.
     *
     * Because onCompleted happens BEFORE the specific transition onCompleted,
     * we need to match this event exactly.  Otherwise, the next events are dispatched too soon.
     */
    #[AsCompletedListener(WF::TRANSITION_ARCHIVE, priority: 1000)]
    public function onArchiveCompleted(CompletedEvent $event): void
    {
        $asset = $this->getAsset($event);

        if (!$asset->originalUrl || !$asset->tempFilename) {
            $this->logger->warning('Archive transition missing source data.', [
                'assetId' => $asset->id,
            ]);
            return;
        }

        $key = $this->archiveService->keyForUrl($asset->originalUrl);
        $payloadPath = $this->archiveService->payloadPath($key, $asset->ext);

        $contents = file_get_contents($asset->tempFilename);
        if ($contents === false) {
            throw new RuntimeException(sprintf(
                'Failed reading temp file "%s".',
                $asset->tempFilename
            ));
        }

        $this->atomicFileWriter->write(
            $payloadPath,
            $contents,
            ensureDir: true
        );
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_ANALYZE)]
    public function onAnalyze(TransitionEvent $event): void
    {
        $asset   = $this->getAsset($event);
        $context = $event->getContext();

        // need a constant for the small!
        $url = $this->mediaUrlGenerator->resize($asset->originalUrl, self::THUMBHASH_PRESET);
        // fetch it to seed the cache and get the thumbHasn
        $tempFilename = $this->downloadUrl($url, 'small.jpg'); // $asset->tempFilename);
        $content = file_get_contents($tempFilename);
        $this->assetPreviewService->maybeComputeThumbhash($asset, self::THUMBHASH_PRESET, $content);
        // arguably colors could be done on a bigger image
        $this->assetPreviewService->maybeComputePaletteAndPhash($asset, self::THUMBHASH_PRESET, $tempFilename);
        dd($asset->context);

        return;

        // Guard: only analyze images for now; audio/video can get their own probes
        if (!$asset->mime || !str_starts_with($asset->mime, 'image/')) {
            $this->logger->info("Skipping analysis for non-image asset {$asset->contentHashHex()} ({$asset->mime})");
            return;
        }

        // Source path (Liip expects a web URL path, not fs); adapt to your storage scheme
        $sourceUrlPath = $asset->storageKey ?? $asset->originalUrl;
        if (!$sourceUrlPath) {
            $this->logger->warning("Asset {$asset->contentHashHex()} has no sourceUrlPath for analysis.");
            return;
        }

        // Run previews/analysis on small + medium variants
        $presets = $context['presets'] ?? ['small'];
            $results = $this->assetPreviewService->processPresets($asset, $sourceUrlPath, $presets);
            $this->logger->info("Analysis complete for {$asset->id}", $results);
        try {
        } catch (\Throwable $e) {
            $this->logger->error("Analysis failed for {$asset->id}: {$e->getMessage()}");
        }

        $this->em->persist($asset);
        $this->em->flush();
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
            $response = $client->request('GET', $url);

// Responses are lazy: this code is executed as soon as headers are received
            $code = $response->getStatusCode();
            if (200 !== $code) {
                throw new \Exception("Problem with $url " . $response->getStatusCode(), code: $code);
            }

            $fileHandler = fopen($tempFile, 'w');
            foreach ($client->stream($response) as $chunk) {
                fwrite($fileHandler, $chunk->getContent());
            }
            fclose($fileHandler);

        }

        $this->logger->info("Downloaded url: $url as $tempFile " . filesize($tempFile));
        return $tempFile;

    }

    private function processLocalFile(string $localAbsoluteFilename, Asset $asset): array
    {
        $asset->ext = pathinfo($localAbsoluteFilename, PATHINFO_EXTENSION);
        $mimeType = mime_content_type($localAbsoluteFilename); //
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
                        //clean exif data : remove EXIF key
                        $exif = array_filter($exif, fn($key) => !str_starts_with($key, 'EXIF'), ARRAY_FILTER_USE_KEY);
                        // flatten nested arrays but preserve keys
                        $exif = iterator_to_array(
                            new \RecursiveIteratorIterator(
                                new \RecursiveArrayIterator($exif)
                            ),
                            true // preserve keys
                        );
//                    $media->setExif($exif);
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

}
