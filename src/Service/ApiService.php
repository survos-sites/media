<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Media;
use App\Entity\Thumb;
use App\Entity\User;
use App\Message\DownloadImage;
use App\Message\SendWebhookMessage;
use App\Repository\MediaRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use Liip\ImagineBundle\Events\CacheResolveEvent;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Cache\Helper\PathHelper;
use Liip\ImagineBundle\Service\FilterService;
use Psr\Log\LoggerInterface;
use Survos\SaisBundle\Model\AccountSetup;
use Survos\SaisBundle\Service\SaisClientService;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thumbhash\Thumbhash;
use function JmesPath\search;
use function Symfony\Component\String\u;

class ApiService
{
    public function __construct(
        private readonly LoggerInterface                          $logger,
        private readonly HttpClientInterface                      $httpClient,
        private readonly HttpClientInterface                      $localHttpClient,
        private readonly EntityManagerInterface                   $entityManager,
        private readonly MediaRepository                          $mediaRepository,
        private readonly MessageBusInterface                      $messageBus,
        #[Autowire('@liip_imagine.service.filter')]
        private readonly FilterService                            $filterService,
        private SerializerInterface                               $serializer,
        private NormalizerInterface                               $normalizer,
        #[Autowire('%env(HTTP_PROXY)%')] private ?string $proxyUrl,
        #[Autowire('%env(SAIS_API_ENDPOINT)%')] private string    $apiEndpoint,

        private readonly SaisClientService $saisClientService,
        private readonly UserRepository $userRepository,
    ) {
        // if no proxyUrl is set and environment is dev, default to "http://127.0.1:7080"
        if (!$this->proxyUrl && ($_ENV['APP_ENV'] ?? null) === 'dev') {
            $this->proxyUrl = 'http://127.0.1:7080';
        }

        if ($proxyUrl) {
            assert(!str_contains($proxyUrl, 'http'), "no scheme in the proxy!");
        }
    }

    #[AsEventListener()]
    public function onCacheResolve(CacheResolveEvent $event): void
    {
        dd($event);
    }

//    public function getMedia(?string $url = null, ?string $path = null): ?Media
//    {
//        assert(false, "need root?");
//        if ($url && $path) {
//            throw new \RuntimeException('Cannot have both path and url');
//        }
//        if (!$url && !$path) {
//            throw new \RuntimeException('Must specify a url or path');
//        }
//        if (!$path) {
//            $code = SaisClientService::calculateCode($url);
//            $path = SaisClientService::calculatePath($code);
//        }
//        if (!$media = $this->mediaRepository->find($path)) {
//            dd($path . " missing from media");
//        }
//        return $media;
//    }

    public function accountSetup(AccountSetup $as) {
        $new = false;
        if (!$user = $this->userRepository->find($as->root)) {
            $user = new User(
                $as->root,
                $as->approx,
            );
            $this->entityManager->persist($user);
            $new = true;
        }
        $user
            ->setThumbCallbackUrl($as->thumbCallbackUrl)
            ->setMediaCallbackUrl($as->mediaCallbackUrl);
        $this->entityManager->flush();
        // Use serialization groups to prevent circular reference
        $userData = $this->normalizer->normalize($user, 'object', ['groups' => ['user.read']]);
        return [$userData, $new];
    }

    public function dispatchWebhook(?string $callbackUrl, Media|Thumb $media): void
    {
        if ($callbackUrl) {
            $content = $this->normalizer->normalize($media, 'object', ['groups' => ['media.read','thumb.read', 'marking']]);
//            $env = $this->messageBus->dispatch( new SendWebhookMessage($callbackUrl, $content) );
        }
    }

    #[AsMessageHandler()]
    public function onWebhookMessage(SendWebhookMessage $message): mixed
    {
        $options = [
            'timeout' => 4,
            'json' => $this->normalizer->normalize($message->getData()),
            'proxy' => $this->proxyUrl,
            //'proxy' => "http://127.0.1:7080",
            //ignore ssl verification
            // 'verify_peer' => false,
            // 'verify_host' => false,
        ];

        // skip if empty
        if (!$url = $message->getCallbackUrl()) {
            return "No callback URL";
        }

        if (str_contains($url, '.wip') && empty($this->proxyUrl)) {
            return "webhooks to .wip are ignored on production or without a proxy";
        }


//        $url = 'https://d5b2-2607-fb91-870-3d2-1769-9a25-d2a2-96b7.ngrok-free.app/handle_media';
        //$url = 'https://md.wip/webhook';
//        dd($message, $this->proxyUrl, $message->getData(), $options);
//        $x = file_get_contents($url); dd($x);
        //dump($url, $options);

        $request = $this->httpClient->request('POST', $url, $options);

        //dd($request->getStatusCode(),$request->getContent());

        if ($request->getStatusCode() !== 200) {
            //dd($message, $url, $request->getStatusCode());
            //just sent message , url , status code to console
            //dd($request->getContent());
        }

        //if response code is 404 delete the media
        if ($request->getStatusCode() === 404) {
            // $this->logger->error("Error sending webhook to " . $message->getCallbackUrl() . " with status code: " . $request->getStatusCode());
            // //show response body
            // $this->logger->error("Error sending webhook to " . $message->getCallbackUrl() . " with response: " . $request->getContent());
            // //delete media
            // $media = $this->mediaRepository->find($message->getData()['code']);
            // if ($media) {
            //     $this->entityManager->remove($media);
            //     $this->entityManager->flush();
            // }
        }


        //return $options;
        //return response body
        return [
            "message" => $this->serializer->serialize($message, 'json'),
            "status" => $request->getStatusCode(),
            //"headers" => $request->getHeaders(false),
            "content" => $request->getContent(false),
        ];

        return __METHOD__;
    }



    public function updateDatabase(
        string $code,
        string $path,
        string $mimeType,
        string $url,
        int $size,
        string $root = 'default' // Add root parameter with default value
    ): Media
    {

        if (!$media = $this->mediaRepository->find($code)) {
            // Use correct Media constructor parameters: root is required first parameter
            $media = new Media(root: $root, code: $code, path: $path, originalUrl: $url);
            $this->entityManager->persist($media);
        }
        $media
            ->setPath($path)
            ->setOriginalUrl($url)
            ->setMimeType($mimeType)
            ->setSize($size);
        $this->entityManager->flush();
        return $media;

    }


}
