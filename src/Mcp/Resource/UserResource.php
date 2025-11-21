<?php

namespace App\Mcp\Resource;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ecourty\McpServerBundle\Attribute\AsResource;
use Ecourty\McpServerBundle\IO\Resource\ResourceResult;
use Ecourty\McpServerBundle\IO\Resource\TextResource;
use Symfony\Component\Serializer\SerializerInterface;

#[AsResource(
    uri: 'database://user/{id}',
    name: 'user_data',
    title: 'Get User Data',
    description: 'Gathers the data of a user by their ID.',
    mimeType: 'application/json',
)]
class UserResource
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(string $id): ResourceResult
    {
        $user = $this->entityManager->find(User::class, $id);
        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        $stringifiedUserData = $this->serializer->serialize($user, 'json', ['groups' => 'user.read']);

        return new ResourceResult([
            new TextResource(
                name: User::class,
                title: $id,
                uri: 'database://user/' . $id,
                mimeType: 'application/json',
                text: $stringifiedUserData,
            ),
        ]);
    }
}
