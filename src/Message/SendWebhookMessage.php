<?php

namespace App\Message;

use Survos\SaisBundle\Model\DownloadPayload;
use Survos\SaisBundle\Model\ThumbPayload;

final class SendWebhookMessage
{

     public function __construct(
         private ?string $callbackUrl,
         private array $payload,
     ) {
     }

    public function getCallbackUrl(): ?string
    {
        return $this->callbackUrl;
    }

    public function getData(): array
    {
        return $this->payload;
    }

}
