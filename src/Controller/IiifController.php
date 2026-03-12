<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Service\AssetRegistry;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class IiifController extends AbstractController
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetRegistry $assetRegistry,
    ) {
    }

    #[Route('/iiif/2/{id}/info.json', name: 'iiif_image_info', methods: ['GET'])]
    public function info(string $id): JsonResponse
    {
        $asset = $this->findAsset($id);
        [$width, $height] = $this->dimensions($asset);

        $sizes = [];
        foreach (MediaUrlGenerator::PRESETS as $preset) {
            [$w, $h] = $preset['size'];
            $sizes[] = ['width' => (int) $w, 'height' => (int) $h];
        }

        return $this->json([
            '@context' => 'https://iiif.io/api/image/2/context.json',
            '@id' => $this->generateUrl('iiif_image_base', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_URL),
            'protocol' => 'https://iiif.io/api/image',
            'width' => $width,
            'height' => $height,
            'profile' => ['https://iiif.io/api/image/2/level0.json'],
            'tiles' => [],
            'sizes' => $sizes,
        ]);
    }

    #[Route('/iiif/2/{id}', name: 'iiif_image_base', methods: ['GET'])]
    public function base(string $id): JsonResponse
    {
        return $this->info($id);
    }

    #[Route('/iiif/2/{id}/{region}/{size}/{rotation}/{quality}.{format}', name: 'iiif_image', methods: ['GET'])]
    public function image(string $id, string $region, string $size, string $rotation, string $quality, string $format): RedirectResponse
    {
        $asset = $this->findAsset($id);

        if ($format !== 'jpg' && $format !== 'jpeg') {
            throw new BadRequestHttpException('Only jpg is supported for IIIF level 0.');
        }
        if ($region !== 'full') {
            throw new BadRequestHttpException('Only full region is supported for IIIF level 0.');
        }
        if ($rotation !== '0') {
            throw new BadRequestHttpException('Only 0 rotation is supported for IIIF level 0.');
        }
        if (!in_array($quality, ['default', 'color', 'gray', 'bitonal'], true)) {
            throw new BadRequestHttpException('Unsupported quality.');
        }

        $preset = $this->presetFromIiifSize($size);
        $url = $this->assetRegistry->imgProxyUrl($asset, $preset);

        return new RedirectResponse($url, 302);
    }

    #[Route('/iiif/3/{id}/mirador', name: 'iiif_mirador', methods: ['GET'])]
    public function mirador(string $id): RedirectResponse
    {
        $manifestUrl = $this->generateUrl('iiif_manifest', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
        return new RedirectResponse(
            'https://projectmirador.org/embed/?iiif-content=' . urlencode($manifestUrl),
            302,
        );
    }

    #[Route('/iiif/3/{id}/uv', name: 'iiif_uv', methods: ['GET'])]
    public function universalViewer(string $id): RedirectResponse
    {
        $manifestUrl = $this->generateUrl('iiif_manifest', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
        return new RedirectResponse(
            'https://demo.universalviewer.io/uv.html?manifest=' . urlencode($manifestUrl),
            302,
        );
    }

    #[Route('/iiif/3/{id}/manifest', name: 'iiif_manifest', methods: ['GET'])]
    public function manifest(string $id): JsonResponse
    {
        $asset = $this->findAsset($id);
        [$width, $height] = $this->dimensions($asset);

        $manifestId = $this->generateUrl('iiif_manifest', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_URL);
        $canvasId = $manifestId . '/canvas/p1';
        $annotationPageId = $manifestId . '/page/p1';
        $annotationId = $manifestId . '/annotation/p1-image';

        $bodyId = $this->generateUrl(
            'iiif_image',
            [
                'id' => $asset->id,
                'region' => 'full',
                'size' => 'max',
                'rotation' => '0',
                'quality' => 'default',
                'format' => 'jpg',
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $thumbId = $this->generateUrl(
            'iiif_image',
            [
                'id' => $asset->id,
                'region' => 'full',
                'size' => '192,',
                'rotation' => '0',
                'quality' => 'default',
                'format' => 'jpg',
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $label = $asset->context['title'] ?? ('Asset ' . $asset->id);

        return $this->json([
            '@context' => 'https://iiif.io/api/presentation/3/context.json',
            'id' => $manifestId,
            'type' => 'Manifest',
            'label' => ['en' => [(string) $label]],
            'thumbnail' => [[
                'id' => $thumbId,
                'type' => 'Image',
                'format' => 'image/jpeg',
                'width' => 192,
                'height' => 192,
            ]],
            'items' => [[
                'id' => $canvasId,
                'type' => 'Canvas',
                'width' => $width,
                'height' => $height,
                'items' => [[
                    'id' => $annotationPageId,
                    'type' => 'AnnotationPage',
                    'items' => [[
                        'id' => $annotationId,
                        'type' => 'Annotation',
                        'motivation' => 'painting',
                        'target' => $canvasId,
                        'body' => [
                            'id' => $bodyId,
                            'type' => 'Image',
                            'format' => 'image/jpeg',
                            'width' => $width,
                            'height' => $height,
                            'service' => [[
                                'id' => $this->generateUrl('iiif_image_base', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_URL),
                                'type' => 'ImageService2',
                                'profile' => 'level0',
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ]);
    }

    private function presetFromIiifSize(string $size): string
    {
        if ($size === 'full' || $size === 'max') {
            return MediaUrlGenerator::PRESET_LARGE;
        }

        if (preg_match('/^(\d+),$/', $size, $m) === 1) {
            $requestedWidth = (int) $m[1];
            $candidates = [];
            foreach (MediaUrlGenerator::PRESETS as $presetName => $preset) {
                $candidates[$presetName] = (int) $preset['size'][0];
            }

            asort($candidates);
            foreach ($candidates as $presetName => $width) {
                if ($requestedWidth <= $width) {
                    return $presetName;
                }
            }
            return MediaUrlGenerator::PRESET_LARGE;
        }

        throw new BadRequestHttpException('Unsupported IIIF size. Use max, full, or {w},');
    }

    private function findAsset(string $id): Asset
    {
        $asset = $this->assetRepository->find($id);
        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException(sprintf('Asset not found: %s', $id));
        }

        return $asset;
    }

    private function dimensions(Asset $asset): array
    {
        $fallback = MediaUrlGenerator::PRESETS[MediaUrlGenerator::PRESET_LARGE]['size'];
        $width = $asset->width ?? (int) $fallback[0];
        $height = $asset->height ?? (int) $fallback[1];

        return [$width, $height];
    }
}
