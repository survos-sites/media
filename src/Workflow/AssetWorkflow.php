<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Service\AssetRegistry;
use \RuntimeException as RuntimeException;
use App\Entity\Asset;
use App\Entity\AssetPath;
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
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
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
use Survos\GoogleSheetsBundle\Service\GoogleDriveService;
use App\Workflow\AssetFlow as WF;
use App\Workflow\VariantFlowDefinition as VWF;

#[Workflow(name: WF::WORKFLOW_NAME, supports: [Asset::class])]
class AssetWorkflow
{
    const THUMBHASH_PRESET = 'small';
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
        private readonly ApiService                             $apiService,
//        #[Target(TWF::WORKFLOW_NAME)] private WorkflowInterface $thumbWorkflow,
        #[Target(VWF::WORKFLOW_NAME)] private WorkflowInterface $variantWorkflow,
        #[Target(WF::WORKFLOW_NAME)] private WorkflowInterface $assetWorkflow,
        private SerializerInterface                            $serializer,
        private NormalizerInterface                            $normalizer,
        #[Autowire('%env(SAIS_API_ENDPOINT)%')]
        private string                                         $apiEndpoint,
        #[Autowire('%kernel.project_dir%/public/temp')]
        private string                                         $tempDir,
        private readonly EntityManagerInterface                $entityManager,
        private readonly AsyncQueueLocator                     $asyncQueueLocator,
        private readonly VariantPlan                           $plan,
        private readonly StorageService                        $storageService, private readonly AssetRegistry $assetRegistry,
        private readonly ?FilesystemOperator                   $archiveStorage = null,
        private ?GoogleDriveService                            $driveService   = null,

    ) {
    }

    /** @return Asset */
    private function getAsset(Event $event): Asset
    {
        /** @var Asset $asset */ $asset = $event->getSubject();
        return $asset;
    }

    private function uploadToArchive(Asset $asset)
    {
        if (!$asset->tempFilename) {
            throw new RuntimeException(sprintf(
                'Missing temporary filename for archive (asset %s).',
                $asset->id
            ));
        }

        if (!is_file($asset->tempFilename)) {
            throw new RuntimeException(sprintf(
                'Temporary file missing on disk during archive (asset %s, path "%s").',
                $asset->id,
                $asset->tempFilename
            ));
        }

        // Derive extension from actual MIME
        $extension = MediaKeyService::extensionFromMime($asset->mime);

        // Deterministic archive key from original URL
        $path = MediaKeyService::archivePathFromUrl(
            $asset->originalUrl,
            $extension
        );

        $stream = fopen($asset->tempFilename, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to open temporary file for streaming.');
        }

        try {
            // Idempotency: if object already exists, verify integrity
            if ($this->archiveStorage->fileExists($path)) {
                $remoteSize = $this->archiveStorage->fileSize($path);
                $localSize  = filesize($asset->tempFilename);

                if ($remoteSize !== $localSize) {
                    throw new RuntimeException(
                        sprintf(
                            'Archive object exists with mismatched size (remote=%d, local=%d).',
                            $remoteSize,
                            $localSize
                        )
                    );
                }
            } else {
                // Stream upload (constant memory) — ensure public visibility
                $this->archiveStorage->writeStream(
                    $path,
                    $stream,
                    ['visibility' => Visibility::PUBLIC]
                );
            }

        } finally {
            fclose($stream);
        }

        $asset->storageKey = $path;
        $asset->storageBackend = 'archive';
        $archiveUrl = $this->assetRegistry->s3Url($asset);
        // so that thumbs can be served without redirects
        $asset->smallUrl = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
        $asset->archiveUrl = $archiveUrl; // if salt expires, this isn't true.
        unlink($asset->tempFilename);
        $asset->tempFilename = null;
        $this->em->flush();
    }

//    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_ARCHIVE)]
    public function onArchive(TransitionEvent $event): void
    {
        return; // no-op, everything now in download.
        $asset = $this->getAsset($event);
//        $this->uploadToArchive($asset);
        dd(onArchive: $asset);


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
        [$path, $absolutePath] = $this->downloadFileToLocalStorage($url, $path);
            // no network calls! Only what we need while we have local
            $fileData = $this->processLocalFile($absolutePath, $asset);
            try {
            } catch (\Exception $e) {
                $asset->statusCode = $e->getCode();
                return;
            }
        $asset->tempFilename = $absolutePath;
            assert(file_exists($absolutePath), "Missing $absolutePath");
            $this->uploadToArchive($asset);

        // now we can save everything and move to the next step.
        $this->em->flush();
        // at this point, we have extracted all we need from the local file.  we can now archive in the next step.
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
            if (is_resource($stream)) {
                fclose($stream);
            }
            $response->cancel();

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
     *
     * Because onCompleted happens BEFORE the specific transition onCompleted,
     * we need to match this event exactly.  Otherwise, the next events are dispatched too soon.
     */
    // #[AsCompletedListener(WF::TRANSITION_ARCHIVE, priority: 1000)] NOT THIS!  See note above
    #[AsCompletedListener(priority: 1000)]
    public function onCompleted(CompletedEvent $event): void
    {
        $asset = $this->getAsset($event);
        $this->em->flush();
        // don't detach until AFTER completed is called, or we won't save the marking.
        $this->em->detach($asset);
        return;
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
    public function onLocalAnalyze(TransitionEvent $event): void
    {
        // Analysis now happens AFTER archive using S3-backed URLs
        $asset = $this->getAsset($event);

        if (!$asset->tempFilename || !$asset->mime || !str_starts_with($asset->mime, 'image/')) {
            $this->logger->info("Skipping analysis for non-image or undownloaded asset ({$asset->mime})");
            return;
        }

        // Use imgproxy / remote access instead of local temp files
        [$tw, $th, $pixels] = $this->assetPreviewService->resizeForThumbHashFromUrl($asset->archiveUrl);

        $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
        $key  = Thumbhash::convertHashToString($hash);

        $asset->context ??= [];
        $asset->context['thumbhash'] = $key;

        $this->assetPreviewService->maybeComputePaletteAndPhash(
            $asset,
            self::THUMBHASH_PRESET,
            $asset->archiveUrl
        );

        unset($pixels);

        $this->em->flush();
//        $this->em->detach($asset);
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


}
