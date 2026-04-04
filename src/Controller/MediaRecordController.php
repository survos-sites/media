<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaRecord;
use App\Repository\MediaRecordRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/media-record')]
final class MediaRecordController extends AbstractController
{
    #[Route('/browse', name: 'media_record_browse')]
    public function browse(): Response
    {
        return $this->render('app/browse-media-records.html.twig', [
            'class' => MediaRecord::class,
            'apiCall' => $this->generateUrl('_api_/media_records{._format}_get_collection', ['_format' => 'jsonld']),
        ]);
    }

    #[Route('/{id}', name: 'media_record_show', requirements: ['id' => '[0-9a-f]{16}'], options: ['expose' => true])]
    public function show(MediaRecord $mediaRecord, MediaRecordRepository $mediaRecordRepository): Response
    {
        $mediaRecord = $mediaRecordRepository->find($mediaRecord->id) ?? $mediaRecord;

        return $this->render('media_record/show.html.twig', [
            'mediaRecord' => $mediaRecord,
        ]);
    }
}
