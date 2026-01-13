<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Entity\Media;
use App\Entity\User;
use App\Form\AccountSetupType;
use App\Form\ProcessPayloadType;
use App\Message\SendWebhookMessage;
use App\Repository\AssetPathRepository;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use App\Service\ApiService;
use App\Workflow\AssetFlow;
use Doctrine\ORM\EntityManagerInterface;
use Ecourty\McpServerBundle\Service\ResourceRegistry;
use Ecourty\McpServerBundle\Service\ToolRegistry;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Zenstruck\Messenger\Monitor\Stamp\DescriptionStamp;
use Zenstruck\Messenger\Monitor\Stamp\TagStamp;
use function Symfony\Component\Clock\now;

class ApiController extends AbstractController implements TokenAuthenticatedController
{

    public function __construct(
        private MessageBusInterface          $messageBus,
        private EntityManagerInterface       $entityManager,
        private NormalizerInterface          $normalizer,
        private AsyncQueueLocator            $asyncQueueLocator,

        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface     $logger,
        private readonly ApiService          $apiService,
        private readonly AssetRepository $assetRepository,
        private readonly AssetPathRepository $assetPathRepository,
        private ?ResourceRegistry             $resourceRegistry=null,
        private ?ToolRegistry                 $toolRegistry=null,
    )
    {
    }

    // this is in the calling application, here for testing only
    #[Route('/handle_image_resize', name: 'handle_image_resize')]
    #[Route('/handle_media', name: 'handle_media')]
    public function handleResizeImage(
        Request $request,
//        #[MapRequestPayload] ?ThumbPayload $thumbPayload=null,
    ): Response
    {
        return $this->json(['status' => 'ok']);
        return $this->json($thumbPayload);
    }


    #[Route('/ui/account_setup', name: 'app_account_setup_ui', methods: ['POST', 'GET'])]
    #[Template('test-dispatch.html.twig')]
    public function testAccountSetup(
        UrlGeneratorInterface $urlGenerator,
        Request               $request
    ): Response|array
    {
        $thumbCallback = $urlGenerator->generate('handle_image_resize', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $mediaCallback = $urlGenerator->generate('handle_media', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $payload = new AccountSetup(
            'test',
            approx: 500,
            mediaCallbackUrl: $mediaCallback,
            thumbCallbackUrl: $thumbCallback,
        );

        $form = $this->createForm(AccountSetupType::class, $payload);
        $form->handleRequest($request);
        // @todo: validate the API key
        if ($form->isSubmitted() && $form->isValid()) {
            // get the payload
            /** @var AccountSetup $payload */
            $payload = $form->getData();
            $response = $this->acccountSetup($payload);
            $results = json_decode($response->getContent());
        }

        return [
            'form' => $form->createView(),
            'results' => $results ?? []
        ];
    }

    #[Route('/ui/dispatch_process', name: 'app_dispatch_process_ui', methods: ['POST', 'GET'])]
    #[Template('test-dispatch.html.twig')]
    public function testDispatch(
        UrlGeneratorInterface $urlGenerator,
        Request               $request
    ): Response|array
    {

        // this could also be an array of MediaModel, and probably should be!
        $code = now()->format('md-H:i:s');
        $processPayload = new ProcessPayload(
            null,
            [
                [
                    // or see https://dummyjson.com/docs/image
                    'url' => 'https://dummyimage.com/600x400/000/fff&text=' . $code,
                    'context' => [
                        'objId' => 201212
                    ]
                ]
//            'https://cdn.dummyjson.com/products/images/beauty/Red%20Nail%20Polish/1.png'
            ]
        );
        $processPayload->wait = true;
        $form = $this->createForm(ProcessPayloadType::class, $processPayload);
        $form->handleRequest($request);
        // @todo: validate the API key
        if ($form->isSubmitted() && $form->isValid()) {
            // get the payload
            /** @var ProcessPayload $payload */
            $payload = $form->getData();
            $response = $this->dispatchProcess($payload);
            $results = json_decode($response->getContent());
        }

        return [
            'form' => $form->createView(),
            'results' => $results ?? []
        ];
    }

    #[Route('/account_setup.{_format}', name: 'app_account_setup', methods: ['POST'])]
    public function acccountSetup(
        #[MapRequestPayload] AccountSetup $payload,
        string                              $_format = 'json'
    ): JsonResponse
    {
        $tool = $this->toolRegistry->getTool(SaisEndpoint::ACCOUNT_SETUP->value);
        $resource = $this->resourceRegistry->getResource('database://user/{id}');
        [$userData, $new] = $this->apiService->accountSetup($payload);
        // userData is already normalized, so we can return it directly
        return $this->json($userData);

    }


        /**
     * When a request comes in, populate the media database and return what we know of media.
     * Dispatch download.
     * After download, dispatch resize
     * @param ProcessPayload $payload
     * @param string $_format
     * @return JsonResponse
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface
     * @todo: handle tasks, which should be batched and recorded
     *
     */
    #[Route('/dispatch_process.{_format}', name: 'app_dispatch_process', methods: ['POST'])]
    public function dispatchProcess(
        #[MapRequestPayload] ProcessPayload $payload,
        string                              $_format = 'json'
    ): JsonResponse
    {
        // Validate that root is not null or empty
//        if (empty($payload->root)) {
//            return $this->json([
//                'error' => 'Root parameter is required and cannot be null or empty'
//            ], 400);
//        }

        foreach ($payload->getMediaObjects() as $image)
        {
            assert($image instanceof MediaModel);
            $url = $image->originalUrl;
            $context = $payload->context;
            $code = SaisClientService::calculateCode($url);

            /** @var Asset $asset */
            if (!$asset = $this->assetRepository->find($code)) {
                $asset = new Asset($url);
                $path = ShardedKey::originalKey($asset->id);
                $shard = pathinfo($path, PATHINFO_DIRNAME);
                if (!$assetPath = $this->assetPathRepository->find($shard)) {
                    $assetPath = new AssetPath($shard);
                    $this->entityManager->persist($assetPath);
                }
                $asset->localDir = $assetPath;
                $assetPath->files++;

                assert($code === $asset->id);
                $this->entityManager->persist($asset);
            }
            $asset->context = $context;
            // add the filters so we have them for after download.
            $filters = $asset->resized??[];
            foreach ($payload->filters as $filter) {
                if (!array_key_exists($filter, $filters)) {
                    $filters[$filter] = [];
                }
            }
            $codes[] = $code;
        }
        $this->entityManager->flush();

        // maybe do the filters here instead of download?

        $listing = $this->assetRepository->findBy(['id' => $codes]);

        if ($payload->wait) {
            $this->asyncQueueLocator->sync = true; // overwrite what's in the config
        }
        $nextTransition = AssetFlow::TRANSITION_DOWNLOAD;

        foreach ($listing as $asset) {
            $this->logger->warning("Dispatching download for {$asset->id} \n");
            $msg = new TransitionMessage(
                $asset->id,
                Asset::class,
                AssetFlow::TRANSITION_DOWNLOAD,
                workflow: AssetFlow::WORKFLOW_NAME,
                context: [
                    'wait' => $payload->wait,
                    'liip' => $payload->filters,
//                    'mediaCallbackUrl' => $payload->mediaCallbackUrl,
//                    'thumbCallbackUrl' => $payload->thumbCallbackUrl,
                ]
            );
            $stamps = $this->asyncQueueLocator->stamps($msg);
            $envelope = $this->messageBus->dispatch($msg, $stamps);
        }

        $response = $this->normalizer->normalize($listing, 'object', ['groups' => ['asset.read', 'marking']]);
        foreach ($response as $mediaData) {
//            $envelope = $this->messageBus->dispatch(new SendWebhookMessage($payload->mediaCallbackUrl, $mediaData));
        }
//        dd($listing, $response);

        return $this->json($response);
    }
}
