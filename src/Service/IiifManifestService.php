<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\IiifManifest;
use App\Repository\IiifManifestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IiifManifestService
{
    public function __construct(
        private readonly IiifManifestRepository $iiifManifestRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $contextHints
     * @return array<string,mixed> normalized context hints
     */
    public function attachFromContextHints(Asset $asset, array $contextHints): array
    {
        [$manifestUrl, $manifestData, $source] = $this->extractManifestInput($contextHints);
        if (!is_string($manifestUrl) || $manifestUrl === '') {
            return $contextHints;
        }

        $manifest = $this->iiifManifestRepository->findOneByManifestUrl($manifestUrl) ?? new IiifManifest($manifestUrl);

        if ($manifestData === null && $manifest->manifestJson === null) {
            $manifestData = $this->fetchManifestJson($manifestUrl);
            $source = $manifestData !== null ? 'fetched' : $source;
        }

        if ($manifestData !== null) {
            $parsed = $this->parseManifest($manifestData);
            $manifest->manifestJson = $manifestData;
            $manifest->imageBase = $parsed['image_base'] ?? $manifest->imageBase;
            $manifest->thumbnailUrl = $parsed['thumbnail_url'] ?? $manifest->thumbnailUrl;
            $manifest->label = $parsed['label'] ?? $manifest->label;
            $manifest->width = $parsed['width'] ?? $manifest->width;
            $manifest->height = $parsed['height'] ?? $manifest->height;
            $manifest->fetchedAt = new \DateTimeImmutable();
        }

        $manifest->source = $source;
        $manifest->updatedAt = new \DateTimeImmutable();

        $asset->iiifManifestEntity = $manifest;
        $this->entityManager->persist($manifest);

        if (!isset($contextHints['iiif_base']) && is_string($manifest->imageBase) && $manifest->imageBase !== '') {
            $contextHints['iiif_base'] = $manifest->imageBase;
        }
        if (!isset($contextHints['iiif_thumbnail_url']) && is_string($manifest->thumbnailUrl) && $manifest->thumbnailUrl !== '') {
            $contextHints['iiif_thumbnail_url'] = $manifest->thumbnailUrl;
        }

        return $contextHints;
    }

    /**
     * @param array<string,mixed> $contextHints
     * @return array{0:?string,1:?array,2:string}
     */
    private function extractManifestInput(array $contextHints): array
    {
        $raw = $contextHints['iiif_manifest'] ?? $contextHints['iiifManifest'] ?? null;
        $rawJson = $contextHints['iiif_manifest_json'] ?? $contextHints['iiifManifestJson'] ?? null;

        if (is_array($rawJson)) {
            return [$this->extractUrl($rawJson), $rawJson, 'inline'];
        }

        if (is_array($raw)) {
            return [$this->extractUrl($raw), $raw, 'inline'];
        }

        if (is_string($raw) && $raw !== '') {
            return [$raw, null, 'reference'];
        }

        return [null, null, 'reference'];
    }

    private function fetchManifestJson(string $manifestUrl): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $manifestUrl);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $data = $response->toArray(false);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            $this->logger->warning('IIIF manifest fetch failed: {url} ({error})', [
                'url' => $manifestUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{image_base:?string,thumbnail_url:?string,label:?string,width:?int,height:?int}
     */
    private function parseManifest(array $manifest): array
    {
        $body = $manifest['items'][0]['items'][0]['items'][0]['body'] ?? [];
        $canvas = $manifest['items'][0] ?? [];

        $service = $body['service'][0] ?? $body['service'] ?? null;
        $imageBase = $this->extractUrl($service);
        if ($imageBase === null) {
            $bodyUrl = $this->extractUrl($body);
            if (is_string($bodyUrl)) {
                $imageBase = preg_replace('#/full/.*$#', '', $bodyUrl) ?: null;
            }
        }

        $thumbnail = $this->extractUrl($manifest['thumbnail'] ?? null);
        if ($thumbnail === null && is_string($imageBase) && $imageBase !== '') {
            $thumbnail = rtrim($imageBase, '/') . '/full/!512,512/0/default.jpg';
        }

        $label = null;
        $labelNode = $manifest['label']['none'][0] ?? $manifest['label']['en'][0] ?? $manifest['label'] ?? null;
        if (is_string($labelNode) && $labelNode !== '') {
            $label = $labelNode;
        }

        $width = isset($body['width']) && is_numeric($body['width']) ? (int) $body['width'] : null;
        $height = isset($body['height']) && is_numeric($body['height']) ? (int) $body['height'] : null;

        if ($width === null && isset($canvas['width']) && is_numeric($canvas['width'])) {
            $width = (int) $canvas['width'];
        }
        if ($height === null && isset($canvas['height']) && is_numeric($canvas['height'])) {
            $height = (int) $canvas['height'];
        }

        return [
            'image_base' => $imageBase,
            'thumbnail_url' => $thumbnail,
            'label' => $label,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function extractUrl(mixed $node): ?string
    {
        if (is_string($node) && $node !== '') {
            return $node;
        }

        if (!is_array($node)) {
            return null;
        }

        foreach (['id', '@id', 'url', 'href', 'src'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                return $node[$key];
            }
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                $url = $this->extractUrl($item);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }
}
