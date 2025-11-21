<?php

// uses Survos Param Converter, from the UniqueIdentifiers method of the entity.

namespace App\Controller;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\IriConverterInterface;
use App\Entity\Media;
use App\Repository\MediaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Traits\HandleTransitionsTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media')]
class MediaCollectionController extends AbstractController
{
    use HandleTransitionsTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly MediaRepository $mediaRepository,
        private ?IriConverterInterface $iriConverter = null
    ) {
    }

    #[Route(path: '/search/{apiRoute}', name: 'media_meili', methods: ['GET'], options: ['en' => 'Browse Media'], priority: 100)]
    public function search(Request $request, string $apiRoute = Media::MEILI_ROUTE): Response
    {
        return $this->render('media/index.html.twig', get_defined_vars() + [
                'class' => Media::class,
//            'project' => ['code' =>'Owner', 'name' => 'Owner', 'rp' => []],
            ]);
//        return $this->render('owner/index_doctrine.html.twig', [
//            'class' => Owner::class
//        ]);
    }


    #[Route(path: '/browse/', name: 'media_browse', methods: ['GET'])]
    #[Route('/index', name: 'media_index', options: ['description' => "Browse with database"])]
    public function browse_media(Request $request,
        #[MapQueryParameter] ?string $code=null,
        #[MapQueryParameter] ?string $marking=null,
    ): Response
    {
        $filter = [];
        if ($marking) {
            $filter['marking'] = $marking;
        }
        if ($code) {
            $filter['root'] = $code;
        }
        $class = Media::class;
        $shortClass = 'Media';
        $useMeili = 'app_browse' == $request->get('_route');
        // this should be from inspection bundle!
        $apiCall = $useMeili
        ? '/api/meili/'.$shortClass
        : $this->iriConverter->getIriFromResource($class, operation: new GetCollection(),
            context: $context ?? [])
        ;

        //tell php stan to ignore line
        $this->apiGridComponent->setClass($class); // @phpstan-ignore-line
        $c = $this->apiGridComponent->getDefaultColumns(); // @phpstan-ignore-line
        $columns = array_values($c);
        $useMeili = 'media_browse' == $request->get('_route');
        // this should be from inspection bundle!
        $apiCall = $useMeili
        ? '/api/meili/'.$shortClass
        : $this->iriConverter->getIriFromResource($class, operation: new GetCollection(),
            context: $context ?? [])
        ;

        return $this->render('browse/media.html.twig', [
            'medias' => $this->mediaRepository->findBy($filter, ['createdAt' => 'DESC'], 50),
            'class' => $class,
            'useMeili' => $useMeili,
            'apiCall' => $apiCall,
            'columns' => $columns,
            'filter' => [],
        ]);
    }

    #[Route('/symfony_crud_index', name: 'media_symfony_crud_index')]
    public function symfony_crud_index(MediaRepository $mediaRepository): Response
    {
        return $this->render('media/index.html.twig', [
            'medias' => $mediaRepository->findBy([], [], 30),
        ]);
    }


}
