<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\AssetPath;
use App\Entity\File;
use App\Entity\Media;
use App\Entity\Thumb;
use App\Entity\Variant;
use App\Form\ProcessPayloadType;
use App\Repository\MediaRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\SaisBundle\Model\ProcessPayload;
use Survos\SaisBundle\Service\SaisClientService;
use Survos\StateBundle\Service\EntityInterfaceDetector;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\ThumbHashBundle\Service\BlurService;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Thumbhash\Thumbhash;

class ClientController extends AbstractController
{


    public function __construct(
        private EntityManagerInterface                                  $entityManager,
        private EntityInterfaceDetector $entityInterfaceDetector,
//        #[AutowireIterator('state.marking_interface')] private iterable $entityClasses,
        private WorkflowHelperService                                   $workflowHelperService,
    )
    {
    }

    #[Route('/status', name: 'app_status')]
    public function status(): array|JsonResponse
    {
        return $this->json([
            'status' => 'okay'
        ]);
    }

    /**
     * @throws \ImagickException
     * @throws \ImagickPixelException
     */
    #[Route('/', name: 'app_homepage')]
    #[Template('homepage.html.twig')]
    public function home(MediaRepository $mediaRepository,
    UserRepository $userRepository,
    #[MapQueryParameter] int $limit = 5
    ): array
    {
        $classes = $this->entityInterfaceDetector->getEntitiesImplementing(MarkingInterface::class);
//        dd($this->entities);
//        foreach ($this->entities as $entity) {
//            dd($entity);
//        }
        $markingCounts = [];
        foreach ($classes as $class) {
//        foreach ($this->workflowHelperService->getWorkflowsGroupedByClass() as $class=>$w) {
            $repo = $this->entityManager->getRepository($class);
            $counts[$class] = $repo->count();
            $markings = $this->workflowHelperService->getCounts($class, 'marking');
            ksort($markings);
            $markingCounts[$class] = $markings;

//            $data = $repo->findBy([], ['createdAt' => 'DESC'], 10);
        }


//        $content = file_get_contents('https://cdn.dummyjson.com/products/images/beauty/Essence%20Mascara%20Lash%20Princess/1.png');
//        $content = file_get_contents('https://cdn.dummyjson.com/products/images/beauty/Essence%20Mascara%20Lash%20Princess/thumbnail.png');
//        $content = file_get_contents(__DIR__ . '/../../sunrise.jpg');
//        $filename = __DIR__ . '/../../walter1.jpg';
//
//        list($width, $height, $pixels) = ThumbHashService::extract_size_and_pixels_with_imagick($content);
////        list($width, $height, $pixels) = ThumbHashService::extract_size_and_pixels_with_gd($content);
//
//        try {
//            $hash = Thumbhash::RGBAToHash($width, $height, $pixels);
//            $key = Thumbhash::convertHashToString($hash); // You can store this in your database as a string
//            $url = Thumbhash::toDataURL($hash);
//        } catch (\Exception $e) {
//            dd($e->getMessage());
//        }
//        dd($width, $height, $hash, $key, $url);

        return [
            'counts' => $counts,
            'markingCounts' => $markingCounts,
            'users' => $userRepository->findAll(),
//            'rows' => $mediaRepository->findBy([], ['createdAt' => 'DESC'], 30)
        ];
    }


    // https://insight.symfony.com/docs/notifications/custom-webhook.html
    // https://medium.com/@skowron.dev/discovering-symfonys-secret-weapon-the-ultimate-guide-to-the-webhook-component-bae1449f4504
// https://dev.to/sensiolabs/how-to-use-the-new-symfony-maker-command-to-work-with-github-webhooks-2c8n
    #[Route('/test-webhook', name: 'app_webhook')]
    public function webhook(Request $request): Response
    {
        return new Response(json_encode($request->request->all(), JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));
    }

}
