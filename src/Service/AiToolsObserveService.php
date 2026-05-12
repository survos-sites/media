<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AiToolsObserveService
{
    public function __construct(
        #[Autowire(service: \App\Ai\SurvosAiToolsPlatform::class)]
        private readonly PlatformInterface $platform,
        #[Autowire('%env(default::AI_TOOLS_MODEL)%')]
        private readonly ?string $defaultModel = null,
    ) {
    }

    /**
     * Each entry in `claims` maps 1:1 to Survos\AiClaimsBundle\Service\RawClaim
     * (predicate, value, confidence, basis, metadata) — feed straight into
     * ClaimIngestor::record(rawClaims: ...) without translation.
     *
     * @return array{
     *     schema_version?: string,
     *     claims?: list<array<string, mixed>>,
     *     run?: array<string, mixed>,
     *     raw_text?: string
     * }
     */
    public function observeImage(string $imageUrl, ?string $model = null): array
    {
        $result = $this->platform
            ->invoke($model ?: ($this->defaultModel ?: 'auto'), new MessageBag(new UserMessage(new ImageUrl($imageUrl))))
            ->getResult();

        if (!$result instanceof TextResult) {
            throw new \RuntimeException(sprintf('ai-tools returned unsupported result type "%s".', $result::class));
        }

        $text = $result->getContent();
        $payload = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('ai-tools returned non-object JSON content.');
        }

        $payload['raw_text'] = $text;

        return $payload;
    }
}
