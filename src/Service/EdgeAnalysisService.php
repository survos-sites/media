<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class EdgeAnalysisService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::EDGE_ANALYSIS_URL)%')] private readonly ?string $endpoint = null,
        #[Autowire('%env(int:SERVICE_HTTP_TIMEOUT_SECONDS)%')] private readonly int $httpTimeoutSeconds = 120,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function analyzeLocalFile(string $localPath, ?string $mime = null): ?array
    {
        $baseUrl = trim((string) $this->endpoint);
        if ($baseUrl === '') {
            return null;
        }

        $endpoint = $this->resolveUploadEndpoint($baseUrl);

        if (!is_file($localPath) || !is_readable($localPath)) {
            $this->logger->warning('EdgeAnalysisService: file not readable: {path}', ['path' => $localPath]);
            return null;
        }

        $mime ??= mime_content_type($localPath) ?: 'application/octet-stream';

        try {
            $fileHandle = fopen($localPath, 'r');
            if ($fileHandle === false) {
                return null;
            }

            try {
                $formData = new FormDataPart([
                    'include_ocr_text' => '0',
                    'file' => new DataPart($fileHandle, basename($localPath), $mime),
                ]);

                $response = $this->httpClient->request('POST', $endpoint, [
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body' => $formData->bodyToIterable(),
                    'timeout' => $this->httpTimeoutSeconds,
                ]);
            } finally {
                fclose($fileHandle);
            }

            $payload = $response->toArray(false);
            if (!is_array($payload)) {
                $this->logger->warning('EdgeAnalysisService: unexpected response shape from {endpoint}', ['endpoint' => $endpoint]);
                return null;
            }

            return $payload;
        } catch (\Throwable $exception) {
            $this->logger->warning('EdgeAnalysisService: {message}', ['message' => $exception->getMessage()]);
            return null;
        }
    }

    private function resolveUploadEndpoint(string $baseUrl): string
    {
        $trimmed = rtrim($baseUrl, '/');
        if (str_ends_with($trimmed, '/analyze/upload')) {
            return $trimmed;
        }

        if (str_contains($trimmed, '/analyze/')) {
            return preg_replace('#/analyze/[^/]+$#', '/analyze/upload', $trimmed) ?? ($trimmed . '/analyze/upload');
        }

        return $trimmed . '/analyze/upload';
    }
}
