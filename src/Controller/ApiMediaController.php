<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Debug-friendly media resolution/probe:
 * - GET  /fetch/media/{id}             (single asset probe)
 * - GET  /api/media/by-ids?id=a,b,c   (also supports multiple id=)
 * - POST /api/media/by-ids            (JSON: {"ids": ["a","b","c"]})
 */
final class ApiMediaController extends AbstractController
{
    public function __construct(public readonly EntityManagerInterface $em) {}

    #[Route('/fetch/media/{id}', name: 'api_media_probe_single', methods: ['GET'])]
    public function probeSingle(string $id): JsonResponse
    {
        /** @var Asset|null $asset */
        $asset = $this->em->getRepository(Asset::class)->find($id);
        if (!$asset) {
            throw new NotFoundHttpException(sprintf('Asset not found: %s', $id));
        }

        return $this->json($this->probeAsset($asset));
    }

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

        /** @var list<Asset> $assets */
        $assets = $qb->getQuery()->getResult();

        $rows = array_map($this->probeAsset(...), $assets);

        return $this->json($rows);
    }

    /**
     * Returns the current known state for one asset, including variants and child derivatives.
     * OCR/AI results are expected in context fields (either parent or children).
     */
    private function probeAsset(Asset $asset): array
    {
        /** @var list<Asset> $children */
        $children = $this->em->getRepository(Asset::class)->findBy(['parentKey' => $asset->id], ['pageNumber' => 'ASC']);

        $thumbs = [];
        $variants = [];
        foreach ($asset->variants as $variant) {
            if ($variant->url) {
                $thumbs[$variant->preset] = $variant->url;
            }
            $variants[] = [
                'id' => $variant->id,
                'preset' => $variant->preset,
                'format' => $variant->format,
                'url' => $variant->url,
                'width' => $variant->width,
                'height' => $variant->height,
                'size' => $variant->size,
                'marking' => $variant->marking,
            ];
        }

        $childRows = array_map(static fn (Asset $child): array => [
            'id' => $child->id,
            'pageNumber' => $child->pageNumber,
            'marking' => $child->marking,
            'mime' => $child->mime,
            'archiveUrl' => $child->archiveUrl,
            'smallUrl' => $child->smallUrl,
            'context' => $child->context,
        ], $children);

        $meta = [
            'mimeType' => $asset->mime,
            'width' => $asset->width,
            'height' => $asset->height,
            'size' => $asset->size,
            'statusCode' => $asset->statusCode,
            'storageKey' => $asset->storageKey,
            'archiveUrl' => $asset->archiveUrl,
            'smallUrl' => $asset->smallUrl,
            'contentHash' => $asset->contentHash,
            'childCount' => $asset->childCount,
            'hasOcr' => $asset->hasOcr,
        ];

        return [
            'id'        => $asset->id,
            'source'    => (string) $asset->originalUrl,
            'marking'   => $asset->marking,
            'thumbs'    => $thumbs,
            'variants'  => $variants,
            'context'   => $asset->context,
            'meta'      => $meta,
            'children'  => $childRows,
            // Optional convenience mirrors for common AI/OCR keys in context.
            'ocr'       => $asset->context['ocr'] ?? null,
            'ai'        => $asset->context['ai'] ?? null,
        ];
    }
}
