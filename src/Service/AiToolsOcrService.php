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
        #[Autowire('%env(default::AI_TOOLS_LOCAL_PATH_HOST_PREFIX)%')]
        private readonly ?string $localPathHostPrefix = null,
        #[Autowire('%env(default::AI_TOOLS_LOCAL_PATH_CONTAINER_PREFIX)%')]
        private readonly ?string $localPathContainerPrefix = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function analyzeUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $payload = [
            'include_ocr_text' => true,
        ];
        if (str_starts_with($url, 'file://')) {
            $localPath = substr($url, 7);
            $mappedPath = $this->mapLocalPathForRemote($localPath);
            $payload['path'] = $mappedPath;
            $payload['image_path'] = $mappedPath;
            $payload['url'] = $url;
            $payload['image_url'] = $url;
            $payload['source'] = 'local_file';
        } else {
            $payload['url'] = $url;
            $payload['image_url'] = $url;
            $payload['source'] = 'url';
        }

        try {
            $response = $this->httpClient->request('POST', '/analyze/type', [
                'json' => $payload,
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

    private function mapLocalPathForRemote(string $localPath): string
    {
        $hostPrefix = is_string($this->localPathHostPrefix) ? rtrim($this->localPathHostPrefix, '/') : '';
        $containerPrefix = is_string($this->localPathContainerPrefix) ? rtrim($this->localPathContainerPrefix, '/') : '';

        if ($hostPrefix === '' || $containerPrefix === '') {
            return $localPath;
        }

        if ($localPath === $hostPrefix || str_starts_with($localPath, $hostPrefix . '/')) {
            $suffix = substr($localPath, strlen($hostPrefix));
            return $containerPrefix . $suffix;
        }

        return $localPath;
    }
}
