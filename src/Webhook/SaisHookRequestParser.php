<?php

namespace App\Webhook;

use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\SchemeRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

use Symfony\Component\Serializer\SerializerInterface;
use App\Entity\Media;

final class SaisHookRequestParser extends AbstractRequestParser
{
    private SerializerInterface $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new IsJsonRequestMatcher(),
            new PathRequestMatcher('/webhook/sais-hook'),
            new MethodRequestMatcher(Request::METHOD_POST),
                        //new SchemeRequestMatcher('https'),
                    ]);
            }

    /**
     * @throws JsonException
     */
    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): ?RemoteEvent
    {
        // TODO: Adapt or replace the content of this method to fit your need.

        // Validate the request against $secret.
        $authToken = $request->headers->get('X-Authentication-Token');
        if ($authToken !== $secret) {
            throw new RejectWebhookException(Response::HTTP_UNAUTHORIZED, 'Invalid authentication token.');
        }

        // Validate the request payload.
        if (!$request->getPayload()->has('name')
            || !$request->getPayload()->has('id')) {
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Request payload does not contain required fields.');
        }
        
        $payload = $request->getPayload();
        if (!$payload->has('media')) {
            throw new RejectWebhookException(Response::HTTP_BAD_REQUEST, 'Request payload does not contain media field.');
        }
        
        $media = $this->serializer->deserialize($payload->get('media'), Media::class, 'json');

        $eventPayload = $payload->all();
        $eventPayload['media'] = $media;

        return new RemoteEvent(
            $payload->getString('name'),
            $payload->getString('id'),
            $eventPayload,
        );
    }
}
