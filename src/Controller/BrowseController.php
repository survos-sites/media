<?php

namespace App\Controller;

use App\Repository\MediaRepository;
use App\Repository\ThumbRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;



class BrowseController extends AbstractController
{


    public function __construct(
        private MediaRepository $mediaRepository,
        private ThumbRepository $thumbRepository
    )
    {
    }

    #[Route('/app/media', name: 'app_media')]
    public function browseMedia(
        #[MapQueryParameter] ?string $marking=null,
        #[MapQueryParameter] ?string $root=null
    ): Response
    {
        $where = [];
        if ($marking) {
            $where['marking'] = $marking;
        }
        if ($root) {
            $where['root'] = $root;
        }
        return $this->render('browse/media.html.twig', [
            'medias' => $this->mediaRepository->findBy($where, [], 40)
        ]);
    }

    #[Route('/app/thumbs', name: 'app_thumbs')]
    public function browseThumbs(
        #[MapQueryParameter] ?string $marking=null,
        #[MapQueryParameter] ?string $code=null,
        #[MapQueryParameter] ?string $size=null,
        #[MapQueryParameter] int $limit = 30,

    ): Response
    {
        $qb = $this->thumbRepository->createQueryBuilder('t');
        $qb->join('t.media', 'm');
        if ($marking) {
            $qb->where($qb->expr()->like('t.marking', ':marking'));
            $qb->setParameter('marking', $marking);
        }
        if ($code) {
            $qb->andWhere($qb->expr()->like('m.root', ':code'));
            $qb->setParameter('code', $code);
        }
        if ($size) {
            $qb->andWhere($qb->expr()->like('t.liipCode', ':size'));
            $qb->setParameter('size', $size);
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        $qb->orderBy('t.id', 'DESC');

//        $where = [];
//        if ($marking) {
//            $where['marking'] = $marking;
//        }
//        if ($code) {
//            $where['media.root'] = $code;
//        }

        return $this->render('browse/thumbs.html.twig', [
            'rows' => $qb->getQuery()->getResult(),
            'controller_name' => 'thumbsController',
        ]);
    }
}
