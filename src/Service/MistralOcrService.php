<?php

namespace App\Service;

// NO!  use symfony/ai































// Simple service — no special bundle needed
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MistralOcrService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%mistral_api_key%')] private string $apiKey,
    ) {}

    public function ocr(string $documentUrl, ?array $pages = null): array
    {
        $payload = [
            'model' => 'mistral-ocr-latest',
            'document' => [
                'type' => 'document_url',
                'document_url' => $documentUrl,
            ],
            'include_image_base64' => true,
        ];
        if ($pages) {
            $payload['pages'] = $pages;
        }

        $response = $this->httpClient->request('POST', 'https://api.mistral.ai/v1/ocr', [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'json' => $payload,
            'timeout' => 300,
        ]);

        return $response->toArray();
    }
}
