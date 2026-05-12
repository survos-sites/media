<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\OpenResponses\Factory;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AutoconfigureTag('ai.platform', ['name' => 'survos_ai_tools'])]
final class SurvosAiToolsPlatform implements PlatformInterface
{
    private PlatformInterface $platform;

    public function __construct(
        #[Autowire('%env(resolve:AI_TOOLS_BASE_URI)%')]
        string $baseUrl,
        #[Autowire('%env(default::AI_TOOLS_API_KEY)%')]
        ?string $apiKey,
        #[Autowire(service: 'ai_tools.client')]
        HttpClientInterface $httpClient,
        EventDispatcherInterface $eventDispatcher,
    ) {
        $this->platform = Factory::createPlatform(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: $httpClient,
            eventDispatcher: $eventDispatcher,
            responsesPath: '/v1/responses',
            name: 'survos_ai_tools',
        );
    }

    public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->platform->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform->getModelCatalog();
    }
}
