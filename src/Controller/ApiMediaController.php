<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\Media;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Debug-friendly media resolution:
 * - GET  /api/media/by-ids?id=a,b,c   (also supports multiple id=)
 * - POST /api/media/by-ids            (JSON: {"ids": ["a","b","c"]})
 */
final class ApiMediaController extends AbstractController
{
    public function __construct(public readonly EntityManagerInterface $em) {}

    #[Route('/fetch/media/by-ids', name: 'api_media_by_ids_get', methods: ['GET'])]
    public function byIdsGet(Request $request,
        #[MapQueryParameter] ?array $ids=null,
        #[MapQueryParameter] ?string $id=null
    ): JsonResponse
    {
        $ids ??=  explode(',', $id);
//        dd($ids);
//        $ids = array_values(array_unique($ids));

        return $this->resolveToJson($ids);
    }

    #[Route('/fetch/media/by-ids', name: 'api_media_by_ids_post', methods: ['POST'])]
    public function byIdsPost(Request $request): JsonResponse
    {
        $payload = $request->getContent() === '' ? [] : (json_decode($request->getContent(), true) ?? []);
        $ids = array_values(array_filter((array) ($payload['ids'] ?? []), static fn($v) => $v !== null && $v !== ''));
        return $this->resolveToJson($ids);
    }

    /**
     * @param string[] $ids
     */
    private function resolveToJson(array $ids): JsonResponse
    {
        if ($ids === []) {
            return $this->json([]);
        }

        // We treat provided identifiers as Media.code values (string codes).
        // If you also want to accept numeric DB IDs, split the list and query both fields.
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Asset::class, 'm')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('m.id', 'ASC');

        /** @var list<Media> $medias */
        $medias = $qb->getQuery()->getResult();

        $rows = array_map($this->normalizeMedia(...), $medias);

        return $this->json($rows);
    }

    /**
     * Normalize a Media entity into the SAIS payload the iterator expects.
     * Uses your known public properties; no method_exists checks.
     *
     * Example shape:
     * [
     *   'id'      => 'forte_0eeaf54f...',
     *   'source'  => 'https://…/original.jpg',
     *   'thumbs'  => ['small' => '…', 'medium' => '…'],
     *   'context' => ['saisCode' => 'forte_0eea…'],
     *   'meta'    => ['width' => 2048, 'height' => 1365, 'mimeType' => 'image/jpeg'],
     *   'updatedAt' => '2025-09-20T10:15:30+00:00'
     * ]
     */
    private function normalizeMedia(Asset $m): array
    {
        // Prefer your denormalized array of size=>url stored in $m->resized
        $thumbs = [];
        foreach ($m->variants as $variant) {
            if ($variant->url) {
                $thumbs[$variant->preset] = $variant->url;
            }
        }

        // Context: always include the SAIS code explicitly
        $context = [
            'saisCode' => $m->id,
            // Add anything else you want to round-trip here (e.g., userId):
            // 'userId' => $m->user?->getId(),
        ];
        $meta = [];

//        $meta = (array)$m;

//        $meta = [
//            'width'     => $m->originalWidth,
//            'height'    => $m->originalHeight,
//            'mimeType'  => $m->mimeType,
//            'size'      => $m->size,
//            'ext'       => $m->ext,
//            'status'    => $m->statusCode,
//            'pHash'     => $m->perceptualHash ?? null,
////            'colors'    => $m->colors,
////            'analysis'  => $m->colorAnalysis,
//            'resizedCount' => \count($thumbs),
//        ];

        // Timestamps may be private DateTimes in your entity; expose formatted strings if available.
        $updatedAt = null;
//        if (property_exists($m, 'updatedAt') && $m->updatedAt instanceof \DateTimeInterface) {
//            $updatedAt = $m->updatedAt->format(DATE_ATOM);
//        }

        return [
            'id'        => $m->id,               // stable identifier (string)
            'source'    => (string) ($m->originalUrl ?? ''),// original image URL
            'thumbs'    => $thumbs,                         // map size => URL
            'context'   => $context,                        // must include saisCode
            'meta'      => $meta,
            'marking' => $m->marking,
            'updatedAt' => $updatedAt,
        ];
    }
}
