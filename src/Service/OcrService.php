<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs Tesseract OCR on a local file via tesseract.survos.com.
 * Called during the download transition while the file is already on disk.
 */
final class OcrService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::OCR_HOST)%')] private readonly ?string $ocrHost = null,
    ) {}

    /**
     * OCR a local file. Returns extracted text, or null if OCR is not configured
     * or the file is not an image type that Tesseract handles.
     */
    public function extractText(string $localPath, ?string $mime = null): ?string
    {
        $host = trim((string) $this->ocrHost);
        if ($host === '') {
            $this->logger->debug('OcrService: OCR_HOST not configured, skipping');
            return null;
        }

        if (!is_file($localPath) || !is_readable($localPath)) {
            $this->logger->warning('OcrService: file not readable: {path}', ['path' => $localPath]);
            return null;
        }

        // Only attempt OCR on image types Tesseract supports
        $mime ??= mime_content_type($localPath) ?: '';
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $options = ['languages' => ['eng'], 'dpi' => 300];

        $formData = new FormDataPart([
            'options' => json_encode($options),
            'file'    => new DataPart(fopen($localPath, 'r'), basename($localPath)),
        ]);

        try {
            $response = $this->httpClient->request('POST', $host, [
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body'    => $formData->bodyToIterable(),
                'timeout' => 120,
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->error('OcrService: HTTP {status} from {host}', ['status' => $status, 'host' => $host]);
                return null;
            }

            $payload = $response->toArray(false);
            $text    = $payload['data']['stdout'] ?? null;

            if (!is_string($text)) {
                $this->logger->error('OcrService: unexpected response shape');
                return null;
            }

            $trimmed = trim($text);
            $this->logger->warning('OcrService: extracted {len} chars from {path}', [
                'len'  => strlen($trimmed),
                'path' => basename($localPath),
            ]);

            return $trimmed;
        } catch (\Throwable $e) {
            $this->logger->error('OcrService: {error}', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
