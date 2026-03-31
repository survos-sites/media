<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiToolsOcrService
{
    public function __construct(
        #[Autowire(service: 'ai_tools.client')]
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::AI_TOOLS_BASE_URI)%')]
        private readonly ?string $baseUri = null,
        #[Autowire('%env(int:SERVICE_HTTP_TIMEOUT_SECONDS)%')]
        private readonly int $httpTimeoutSeconds = 120,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function analyzeUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', '/analyze/type', [
                'json' => [
                    'url' => $url,
                    'include_ocr_text' => true,
                ],
                'timeout' => $this->httpTimeoutSeconds,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);

            $payload = json_decode($body, true);
            if (!is_array($payload)) {
                $payload = [
                    'status' => $status,
                    'ok' => $status < 400,
                    'error' => trim($body) !== '' ? trim($body) : 'non-json response',
                ];
            }

            if ($status >= 400) {
                $payload['status'] = $status;
                $payload['ok'] = false;
                $payload['source_url'] = $url;
                return $payload;
            }

            $payload['status'] = $status;
            $payload['ok'] = true;
            $payload['source_url'] = $url;
            return $payload;
        } catch (\Throwable $exception) {
            $this->logger->error('AiToolsOcrService: {message}', [
                'message' => $exception->getMessage(),
                'base_uri' => $this->baseUri,
                'url' => $url,
            ]);

            return [
                'ok' => false,
                'status' => 500,
                'error' => $exception->getMessage(),
                'source_url' => $url,
            ];
        }
    }
}
