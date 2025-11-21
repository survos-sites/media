<?php

namespace App\Workflow;

use App\Entity\Media;
use App\Entity\Thumb;
use App\Service\ColorAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use League\ColorExtractor\ColorExtractor;
use League\ColorExtractor\Palette;
use Liip\ImagineBundle\Imagine\Cache\Helper\PathHelper;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Survos\StateBundle\Attribute\Workflow;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Survos\ThumbHashBundle\Service\Thumbhash;
use App\Service\ApiService;
use App\Workflow\ThumbFlowDefinition as WF;
class ThumbWorkflow
{
    public function __construct(
        private HttpClientInterface                                                $httpClient,
        #[Autowire('@liip_imagine.service.filter')] private readonly FilterService $filterService,
        #[Autowire('%kernel.project_dir%/public')] private string                  $publicDir,
        private LoggerInterface                                                    $logger,
        private EntityManagerInterface                                             $entityManager,
        private ApiService                                                         $apiService,
        private ColorAnalysisService $colorAnalysisService,

    )
    {
    }

    private function getThumb($event): Thumb
    {
        /** @var Thumb */
        return $event->getSubject();
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_RESIZE)]
    public function onResize(TransitionEvent $event): void
    {
        $thumb = $this->getThumb($event);
        $media = $thumb->getMedia();
//        dd($thumb, $thumb->getMedia()->getPath());

        // the logic from filterAction
        $path = $media->getPath();
        if (!$path) {
            dd($media->getPath(), $media);
        }
        $path = PathHelper::urlPathToFilePath($path);

        $filter = $thumb->liipCode;

        $this->logger->debug("$path =>  $filter ");
        // this actually _does_ the image creation and returns the url
        $url = $this->filterService->getUrlOfFilteredImage(
            path: $path,
            filter: $filter,
            resolver: null,
            webpSupported: true
        );
        $this->logger->debug(sprintf('%s (%s) has been resolved to %s',
            $path, $filter, $url));

        // update the info in the database?  Seems like the wrong place to do this.
        // although this is slow, it's nice to know the generated size.
        $cachedUrl = $this->filterService->getUrlOfFilteredImage(
            $path,
            $filter,
            null,
            true
        );
        // $url _might_ be /resolve?
        $thumb->setUrl($cachedUrl);

//        dd($cachedUrl, parse_url($cachedUrl));
        // there's probably a way to find the path in the service somewhere, but
        // we can get it here.
        $thumbFilename = $this->publicDir . parse_url($cachedUrl, PHP_URL_PATH);
        assert(file_exists($thumbFilename));

        // we probably have this locally, but this will also work if the thumbnails are remote
//        $request = $this->httpClient->request('GET', $cachedUrl);
//        $headers = $request->getHeaders();
//        $content = $request->getContent();
        $content = file_get_contents($thumbFilename);

        /** @var Media $media */
        $size = filesize($thumbFilename); // int)$headers['content-length'][0];
        // this should be a serialized model, not a random array!
        $service = new ThumbHashService();
        $image = new \Imagick();
        $image->readImageBlob($content);
//        dd($image->getSize()); // rows, columns
        $thumb
            ->size = strlen($content);
        $thumb
            ->setW($image->getImageWidth())
            ->setH($image->getImageHeight());
//        dd($image->getImageSignature());


        if ($filter == 'small') {
            list($width, $height, $pixels) = $service->extract_size_and_pixels_with_imagick($content);
            $hash = Thumbhash::RGBAToHash($width, $height, $pixels, 192, 192);
            $key = Thumbhash::convertHashToString($hash); // You can store this in your database as a string
            $media
                ->setBlur($key);
        }

        if ($filter == 'medium') {
            try {
                $palette   = Palette::fromFilename($thumbFilename);
                $extractor = new ColorExtractor($palette);
                $media->colors    = $extractor->extract(5); // still OK if you want a quick list

                // richer analysis
                $analysis = $this->colorAnalysisService->analyze($thumbFilename, top: 5, hueBuckets: 36);
                $media->colorAnalysis = $analysis;
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage());
            }

            try {
                $hasher = new ImageHash(new PerceptualHash());  // pHash
                $hash   = $hasher->hash($thumbFilename);                 // 64-bit hash object
                $media->perceptualHash = (string) $hash;                       // hex string, store as CHAR
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
        // this is done in onCompleted, too
        $media->refreshResized($filter, $size, $url);

        $this->entityManager->flush();
    }

    #[AsCompletedListener(WF::WORKFLOW_NAME, MediaFlowDefinition::TRANSITION_RESIZE)]
    public function onCompletedResize(CompletedEvent $event): void
    {
        $thumb = $this->getThumb($event);
        $media = $thumb->getMedia();
        $media->refreshResized();
        return;

        //log class and method
        $this->logger->warning(sprintf('%s::%s', $thumb->getMedia()->getId(), $thumb->url));
        $this->logger->info(sprintf('%s::%s', __CLASS__, __METHOD__));
        //log $context['thumbCallbackUrl']
        $this->logger->debug(sprintf('Thumb Callback URL: %s', $event->getContext()['thumbCallbackUrl'] ?? 'not set'));

        $context = $event->getContext();
        if($context['thumbCallbackUrl']??null) {
            $this->apiService->dispatchWebhook($context['thumbCallbackUrl'], $thumb);
        }

    }


}
