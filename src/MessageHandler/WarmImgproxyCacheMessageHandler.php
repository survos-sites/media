<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\WarmImgproxyCacheMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs the GET and discards the body — imgproxy has already written the resized
 * derivative to its S3 result cache as a side effect of serving the request, so
 * there's nothing here to save. A failed warm is logged, not retried: it's a
 * best-effort optimization, not something a search page depends on.
 */
#[AsMessageHandler]
final class WarmImgproxyCacheMessageHandler
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WarmImgproxyCacheMessage $message): void
    {
        try {
            $status = $this->httpClient->request('GET', $message->url, ['timeout' => 30])->getStatusCode();
            if ($status >= 400) {
                $this->logger->error('imgproxy cache warm returned {status}: {url}', ['status' => $status, 'url' => $message->url]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('imgproxy cache warm failed: {err} ({url})', ['err' => $e->getMessage(), 'url' => $message->url]);
        }
    }
}
