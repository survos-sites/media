<?php

namespace App\Workflow;

use App\Entity\Media;
use App\Entity\Thumb;
use App\Message\SendWebhookMessage;
use App\Repository\MediaRepository;
use App\Repository\ThumbRepository;
use App\Repository\UserRepository;
use App\Service\ApiService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Survos\SaisBundle\Message\MediaUploadMessage;
use Survos\SaisBundle\Model\DownloadPayload;
use Survos\SaisBundle\Model\MediaModel;
use Survos\SaisBundle\Service\SaisClientService;
use Survos\StateBundle\Attribute\Workflow;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Symfony\Component\String\u;
use Survos\GoogleSheetsBundle\Service\GoogleDriveService;
use App\Workflow\MediaFlowDefinition as WF;
use App\Workflow\ThumbFlowDefinition as TWF;

class MediaWorkflow
{
    public const WORKFLOW_NAME = 'MediaWorkflow';

    public function __construct(
        private MessageBusInterface                                             $messageBus,
        private EntityManagerInterface                                          $entityManager,
        private ThumbRepository                                                 $thumbRepository,
        private readonly FilesystemOperator                                     $localStorage,
        private readonly LoggerInterface                                        $logger,
        private readonly HttpClientInterface                                    $httpClient,
        private UserRepository                                                  $userRepository,
        private readonly ApiService                                             $apiService,
        private readonly MediaRepository                                        $mediaRepository,
        #[Target(ThumbFlowDefinition::WORKFLOW_NAME)] private WorkflowInterface $thumbWorkflow,
        #[Target(MediaFlowDefinition::WORKFLOW_NAME)] private WorkflowInterface $mediaWorkflow,

        #[Autowire('@liip_imagine.service.filter')]
        private readonly FilterService                                          $filterService,
        private SerializerInterface                                             $serializer,
        private NormalizerInterface                                             $normalizer,
        #[Autowire('%env(SAIS_API_ENDPOINT)%')] private string                  $apiEndpoint,
        // the file can be removed as soon as it's renamed and loaded in to local.storage
        #[Autowire('%kernel.project_dir%/public/temp')] private string          $tempDir,
        private readonly ?FilesystemOperator                                    $defaultStorage=null,
        private ?GoogleDriveService                                             $driveService=null,
    )
    {
    }


    /**
     * Heler function to get subject with correct type
     *
     * @param TransitionEvent|CompletedEvent $event
     * @return Media
     */
    private function getMedia(TransitionEvent|CompletedEvent $event): Media
    {
        /** @var Media media */
        return $event->getSubject();
    }



    /**
     * @throws FilesystemException
     * @throws TransportExceptionInterface
     */
    #[AsTransitionListener(WF::WORKFLOW_NAME, MediaFlowDefinition::TRANSITION_DOWNLOAD)]
    public function onDownload(TransitionEvent $event): void
    {
        /** @var Media medi+a */
        $media = $this->getMedia($event);
        $url = $media->getOriginalUrl();

//        $url = 'https://ciim-public-media-s3.s3.eu-west-2.amazonaws.com/ramm/41_2005_3_2.jpg';
//        $url = 'https://coleccion.museolarco.org/public/uploads/ML038975/ML038975a_1733785969.webp';
//        $media->setOriginalUrl($url);
        // we use the original extension

        $uri = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($uri, PATHINFO_EXTENSION);
        $user = $this->userRepository->find($media->getRoot());

        //dd($media,$user);

        assert($user, "missing user/client {$media->getRoot()}, all media must have a root");
        $path = $media->getRoot() . '/' . SaisClientService::calculatePath($user->approxImageCount, $media->getCode());
        if (empty($ext)) {
            $ext = 'tmp'; // Will be corrected after download based on actual mime type
        }
        $media->setExt($ext);
//        dump(__METHOD__, $path);

//        // fix the extension issue, maybe better in a one-off iterator/workflow
//        if ($this->defaultStorage->has($path)) {
//            $this->logger->warning("Adding $ext to $path");
//            $this->defaultStorage->move($path, $path . ".$ext");
//            $media->setPath($path . ".$ext");
//        }

        $path .= '.' . $ext;
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        assert($path, "Missing $path");
        $tempFile = $this->tempDir . '/' . str_replace('/', '-', $path);
//        $tempFile = $media->getCode() . '.' . pathinfo($url, PATHINFO_EXTENSION);// no dirs


        $media->statusCode = 200;

        // Check if media is already fully processed (has resized data, proper status and a file size in bytes)
        if ($media->resizedCount && $media->statusCode === 200 && $media->size) {
            $this->logger->info("Media {$media->getCode()} already processed, skipping download and processing");
            return;
        }

        // if we have size, we've already downloaded the important data.
        if (!$media->size) {
            try {
                $this->downloadUrl($url, $tempFile);
                $fileData = $this->processTempFile($tempFile, $media);
                $tempFile = $fileData['tempFile'];
                $mimeType = $fileData['mimeType']??null;
                $newExt = $fileData['ext'];
                //patch $path
                $path = str_replace('.' . $ext, '.' . $newExt, $path);
                $media
                    ->setPath($path)
                    ->setOriginalUrl($url);
            } catch (\Exception $e) {
                $media->setStatusCode($e->getCode());
                return;
            }
            // we could move to onComplete but we need to pass the temp file
            if (file_exists($tempFile)) {
                $media->tempFilename = $tempFile;
            }
        }
        if (!file_exists($tempFile) || !is_readable($tempFile) || filesize($tempFile) == 0) {
            throw new \Exception("Invalid temp file $tempFile");
        }
//        dd($ext, $url, $uri);
        // if there's no ext, it's a lot more work to get it from the image itself!
//        assert($ext, "@todo: handle missing extension " . $media->getOriginalUrl());



        return;

        $existingFilters = $media->getFilters();

        // @todo: filters, dispatch a synced message since we're in the download
        foreach ($message->getFilters() as $filter) {

            if (!$resized = $this->thumbRepository->findOneBy([
                'media' => $media,
                'liipCode' => $filter
            ])) {
                $resized = new Thumb($media, $filter);
                $this->entityManager->persist($resized);
            }
        }
        // side effect of resize is that media is updated with the filter sized.
        $this->entityManager->flush();
    }

    #[AsCompletedListener(WF::WORKFLOW_NAME, MediaFlowDefinition::TRANSITION_DOWNLOAD)]
    public function onDownloadCompleted(CompletedEvent $event): void
    {
        $media = $this->getMedia($event);
        // upload to localStorage first, so it can be resized
        if ($media->tempFilename && file_exists($media->tempFilename)) {
            $this->uploadUrl($media->tempFilename, 'local',  $this->localStorage, $media);
        }


        $statusCode = $media->getStatusCode();
        $retryCodes = [500, 502, 503, 504, 408, 429];
        $mimeType = (string) $media->getMimeType();

        //if the status code is not 200, then mark as TRANSITION_DOWNLOAD_FAILED
        if ($statusCode !== 200) {
            $this->mediaWorkflow->apply($media, MediaFlowDefinition::TRANSITION_DOWNLOAD_FAILED);
            $this->logger->warning("Download failed for media {$media->getCode()}: $statusCode");
            // if the status code is in the retry codes, we throw exception to retry the download
            if (in_array($statusCode, $retryCodes)) {
                throw new \Exception("Download failed for media {$media->getCode()}: $statusCode");
            }
            return;
        }


        //if $mimeType not image, video, or audio, then mark as TRANSITION_INVALID
        if (!str_starts_with($mimeType, 'image/') && !str_starts_with($mimeType, 'video/') && !str_starts_with($mimeType, 'audio/')) {
            $this->mediaWorkflow->apply($media, MediaFlowDefinition::TRANSITION_INVALID);
            $this->logger->warning("Invalid mime type for media {$media->getCode()}: $mimeType");
            return;
        }

        // if image, do resize / if audio, create cache URLs / if video, do nothing
        if($media->getSize() && str_starts_with($mimeType, 'image/')) {
            $this->resizeMedia($media, Media::FILTERS, $event->getContext());
        } elseif($media->getSize() && str_starts_with($mimeType, 'audio/')) {
            $this->processAudioMedia($media, Media::FILTERS, $event->getContext());
        }

        // if (!$media->getSize() || !str_starts_with((string)$media->getMimeType(), 'image/')) {
        //     $this->mediaWorkflow->apply($media, IMediaWorkflow::TRANSITION_INVALID);
        // } elseif ($media->getStatusCode() !== 200) {
        //     $this->mediaWorkflow->apply($media, IMediaWorkflow::TRANSITION_DOWNLOAD_FAILED);
        // } else {
        //     $this->resizeMedia($media, Media::FILTERS, $event->getContext());
        // }

        $this->entityManager->flush();

        $context = $event->getContext();
        if($context['mediaCallbackUrl']??null) {
            $this->apiService->dispatchWebhook($context['mediaCallbackUrl'], $media);
        }

        // eventually, when the download is complete, dispatch a webhook
//        $env = $this->messageBus->dispatch(new MediaModel(
//            $media->getOriginalUrl(), $media->getRoot(), $media->getPath(), $media->getCode()));
//        return;
//
//        $callbackUrl = match ($media->getRoot()) {
//            'test' => 'https://sais.wip/handle_media'
//        };
//        $envelope = $this->messageBus->dispatch(new SendWebhookMessage($callbackUrl,
//            new DownloadPayload($media->getCode(), $media->getThumbData())
//        ));
//        return;
////        $this->normalizer->normalize($media, 'object', ['groups' => ['media.read']]),
//        dd($envelope, $event->getContext(), $media->getMarking());


        // dispatch the callback request
    }



    private function processTempFile(string $tempFile, Media $media): array
    {
        // @todo: check mimetype and size
        $mimeType = mime_content_type($tempFile);
        $oldExt = $media->getExt();

        //let s correct file extension based on mime type -> extract $ext from $mimeType
        $correctExt = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/wave' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'audio/x-m4a' => 'm4a',
            default => null,
        };

        if ($correctExt) {
            $media->setExt($correctExt);
        } else {
            $this->logger->warning("Unknown mime type $mimeType for temp file $tempFile");
        }

        //apply new extension to temp file
        if ($correctExt && $oldExt && $correctExt !== $oldExt) {
            $newTempFile = preg_replace('/\.' . preg_quote($oldExt, '/') . '$/', '.' . $correctExt, $tempFile);
            if ($newTempFile !== $tempFile && file_exists($tempFile)) {
                rename($tempFile, $newTempFile);
                $tempFile = $newTempFile;
            }
        }

        if (!file_exists($tempFile)) {
            // handle missing file if needed
        }

        //dd($mimeType, $tempFile, filesize($tempFile), $media->getOriginalUrl());

//        $size = getimagesize($media->getOriginalUrl(), $info);
        // Only process image files for dimensions and exif
        if (str_starts_with($mimeType, 'image/')) {
            [$width, $height, $type, $attr] = getimagesize($tempFile, $info);
            if (!$width) {
            // handled in onComplete
            $this->logger->warning("Invalid temp file $tempFile: $mimeType");
            }

            $media
            ->setOriginalWidth($width)
            ->setOriginalHeight($height)
            ->setMimeType($mimeType) // the actual mime type
            ->setSize(filesize($tempFile));

            // problems encoding exif
            // Only read exif for supported image types
            // exif_read_data supports jpeg, tiff, and some webp
            if (in_array($correctExt, ['jpg', 'jpeg', 'tiff', 'webp'])) {
            try {
                $exif = @exif_read_data($tempFile, 'IFD0,EXIF,COMPUTED', true);

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
                $this->logger->warning("EXIF read failed for $tempFile: " . $e->getMessage());
            }
            }
        } else {
            // For non-images, just set mime type and size
            $media
            ->setMimeType($mimeType)
            ->setSize(filesize($tempFile));
        }

        return [
            'tempFile' => $tempFile,
            'mimeType' => $mimeType,
            'ext' => $media->getExt(),
        ];

    }

    private function resizeMedia(Media $media, array $liipCodes, array $context = []): void
    {
        //log status code
        $this->logger->warning("Resizing media {$media->getId()} with status code: {$media->getStatusCode()}");

        if ($media->getStatusCode() !== 200) {
            return;
        }
        //log $liipCodes
        $this->logger->warning("Resizing media {$media->getId()} with filters: " . implode(', ', $liipCodes));

        $stamps = [];
        $stamps[] = new TransportNamesStamp(['thumb.resize']);
//        $stamps[] = new TransportNamesStamp('sync');
        foreach ($liipCodes as $filter) {
            if (!$thumb = $this->thumbRepository->findOneBy([
                'media' => $media,
                'liipCode' => $filter,
            ])) {

                //log $thumb exists
                $this->logger->warning("Creating thumb for media {$media->getId()} with filter: $filter");

                $thumb = new Thumb($media, $filter);
                $media->addThumb($thumb);
                $this->entityManager->persist($thumb);
                $this->entityManager->flush();
            }
            $resizedImages[] = $thumb;

            //log position messgae
            $this->logger->warning("Dispatching resize for thumb {$thumb->getId()} with filter: $filter");

            //log all necessary data thumb id , thumb class, transition, workflow name , context
            $this->logger->warning("Dispatching resize for thumb {$thumb->getId()} with context: " . json_encode($context));
            // $context['thumbId'] = $thumb->getId();
            // $context['thumbClass'] = $thumb::class;
            // $context['transition'] = ThumbFlowDefinition::TRANSITION_RESIZE;
            // $context['workflowName'] = ThumbFlowDefinition::WORKFLOW_NAME;

            $canFlow = $this->thumbWorkflow->can($thumb, TWF::TRANSITION_RESIZE);
            //log canFlow
            $this->logger->warning("Thumb {$thumb->getId()} canFlow: " . ($canFlow ? 'yes' : 'no'));
            //log marking
            $this->logger->warning("Thumb {$thumb->getId()} marking: " . json_encode($this->thumbWorkflow->getMarking($thumb)));

            //force set marking to new
            // $thumb->setMarking('new');
            // $this->logger->warning("Thumb {$thumb->getId()} marking set to new");

            if ($this->thumbWorkflow->can($thumb, TWF::TRANSITION_RESIZE)) {

                //log yes it can
                $this->logger->warning("Thumb {$thumb->getId()} can be resized");

                // now dispatch a message to do the resize
                $envelope = $this->messageBus->dispatch($msg = new TransitionMessage(
                    $thumb->getId(),
                    $thumb::class,
                    ThumbFlowDefinition::TRANSITION_RESIZE,
                    ThumbFlowDefinition::WORKFLOW_NAME,
                    context: $context
                ), $stamps);
            }
        }

    }

    private function processAudioMedia(Media $media, array $compressionLevels, array $context = []): void
    {
        $this->logger->info("Processing audio media {$media->getId()} for compression levels");

        if ($media->getStatusCode() !== 200) {
            return;
        }

        // Check if audio is already processed (has cached URLs)
        if (count($media->resized??[])) {
            $this->logger->info("Audio media {$media->getId()} already has cached URLs, skipping processing");
            return;
        }

        $baseUrl = rtrim($this->apiEndpoint, '/') . '/media/cache';
        $path = $media->getPath();
        $publicCacheDir = $this->tempDir . '/../media/cache'; // public/media/cache

        $resized = [];
        foreach ($compressionLevels as $level) {
            // Create the cache directory structure: public/media/cache/{level}/
            $cacheLevelDir = $publicCacheDir . '/' . $level;
            $cacheFilePath = $cacheLevelDir . '/' . $path;
            $cacheFileDir = dirname($cacheFilePath);

            // Ensure the cache directory exists
            if (!is_dir($cacheFileDir)) {
                mkdir($cacheFileDir, 0755, true);
                $this->logger->info("Created cache directory: {$cacheFileDir}");
            }

            // Copy the original audio file to the cache location if it doesn't exist
            if (!file_exists($cacheFilePath)) {
                try {
                    // Read the original file from local storage
                    if ($this->localStorage->has($path)) {
                        $stream = $this->localStorage->readStream($path);
                        $content = stream_get_contents($stream);
                        fclose($stream);

                        // Write to cache directory
                        file_put_contents($cacheFilePath, $content);
                        $this->logger->info("Created cached audio file: {$cacheFilePath}");
                    } else {
                        $this->logger->error("Original audio file not found in storage: {$path}");
                        continue;
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Failed to create cached audio file {$cacheFilePath}: " . $e->getMessage());
                    continue;
                }
            }

            // Create the URL
            $cacheUrl = "{$baseUrl}/{$level}/{$path}";
            $resized[$level] = $cacheUrl;

            $this->logger->info("Created audio cache URL for level '{$level}': {$cacheUrl}");
        }

        // Store the resized URLs in the media entity
        $media->resized = $resized;

        $this->logger->info("Audio processing complete for media {$media->getId()}", [
            'levels' => array_keys($resized),
            'urls' => $resized
        ]);
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, MediaFlowDefinition::TRANSITION_RESIZE)]
    public function onResize(TransitionEvent $event): void
    {
        $media = $this->getMedia($event);
        $context = $event->getContext();
        $this->resizeMedia($media, Media::FILTERS, $context);
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, MediaFlowDefinition::TRANSITION_ARCHIVE)]
    public function onArchive(TransitionEvent $event): void
    {
        $media = $this->getMedia($event);
        $context = $event->getContext();
        if ($media->tempFilename && file_exists($media->tempFilename)) {
            $this->uploadUrl($tempFile = $media->tempFilename,
                's3',
                $this->defaultStorage, $media);
            // we're done, so delete the temp file
            unlink($tempFile); // so it doesn't fill up the disk
        }
        // @todo: on thumbnails complete, delete from local.storage?
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, MediaFlowDefinition::TRANSITION_REFRESH)]
    public function onRefresh(TransitionEvent $event): void
    {
        $media = $this->getMedia($event);
        $thumbs = [];
        foreach ($media->getThumbs() as $thumb) {
            assert($thumb->getUrl());
            $thumbs[$thumb->liipCode] = $thumb->getUrl();
        }
        $media->resized = $thumbs;
    }

    /**
     * @param string $tempFile
     * @param string $code
     * @return void
     * @throws FilesystemException
     */
    private function uploadUrl(string $tempFile,
                               string $storageCode,
                               FilesystemOperator $storage,
                               Media $media): void
    {
        $path = $media->getPath();
        /**
         * @var  $storageCode
         * @var FilesystemOperator $storage
         */
//        $this->logger->warning("Skipping default storage!! in " . __METHOD__ . '  ' . __CLASS__);
//        foreach ([
//            'default' => $this->defaultStorage, // s3
//             'local' => $this->localStorage
//                 ] as $storageCode => $storage)
        {

            // upload it to long-term storage
            if (!$storage->fileExists($path)) {
                try {
                    // @todo: make sure the mime type and path match before uploading
                    $stream = fopen($tempFile, 'rb');
                    // hmm...
                    $config = [
                        'visibility' => Visibility::PRIVATE,
                        'directory_visibility' => 'public',
                        'mimetype'   => $media->mimeType,
                    ]; // visibility?

                    $directory = pathinfo($path, PATHINFO_DIRNAME);
                    // for the archive
                    if (!$storage->directoryExists($directory)) {
                        $storage->createDirectory($directory);
                    }
                    $this->logger->info(sprintf('Uploading %s: %s ', $storageCode, $tempFile));

                    $config = [
                        'visibility'   => Visibility::PRIVATE, // ACL => private
                        'Metadata'     => ['uploaded-by' => 'app'], // optional
                    ];

                    if ($contentType=$media->mimeType) {
                        $config['ContentType'] = $contentType; // important for S3
                    }

                    // Make sure pointer is at start
                    rewind($stream);

                    $this->logger->info(sprintf('Uploading %s (%d bytes)', $path, filesize($tempFile)));
                    $storage->writeStream($path, $stream, $config);
                    $this->logger->warning("$tempFile UPLOADED to $storageCode:" . $path);
                } catch (FilesystemException|UnableToWriteFile $exception) {
                    // handle the error
                    $this->logger->error($exception->getMessage());
                    return; // transition?
                } finally {

                    $storage->writeStream($path, $stream, $config);
                    $this->logger->info(sprintf('Uploaded! %s', $tempFile));
                    // metadata is stored in $media until we can figure out s3 metadata.
                    // we _can_ get the mimetype though.
                }

            } else {
                $this->logger->warning("$tempFile already EXISTS on $storageCode as $path");
            }
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


}
