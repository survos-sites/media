<?php

namespace App\Mcp\Tools;

use App\Entity\User;
use App\Mcp\Resource\UserResource;
use App\Repository\UserRepository;
use App\Service\ApiService;
use Doctrine\ORM\EntityManagerInterface;
use Ecourty\McpServerBundle\Attribute\AsTool;
use Ecourty\McpServerBundle\Attribute\ToolAnnotations;
use Ecourty\McpServerBundle\IO\Resource\ResourceResult;
use Ecourty\McpServerBundle\IO\ResourceToolResult;
use Ecourty\McpServerBundle\IO\TextToolResult;
use Ecourty\McpServerBundle\IO\ToolResult;
use Ecourty\McpServerBundle\Service\ResourceRegistry;
use Survos\SaisBundle\Enum\SaisEndpoint;
use Survos\SaisBundle\Model\AccountSetup;

#[AsTool(
    name: SaisEndpoint::ACCOUNT_SETUP->value, # Unique identifier for the tool, used by clients to call it
    description: 'Creates a new account (with unique root) in the system', # This description is used by LLMs to understand the tool's purpose
    annotations: new ToolAnnotations(
        title: 'Create an account', // A human-readable title for the tool, useful for documentation
        readOnlyHint: false, // Defines the request is not read-only (creates a user)
        destructiveHint: false, // Defines the request is not destructive (does not delete data)
        idempotentHint: false, // Defines the request cannot be repeated without changing the state
        openWorldHint: false, // The tool does not interact with external systems
    )
)]
class CreateUser
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private ResourceRegistry $resourceRegistry,
        private readonly ApiService $apiService, // Inject the ApiService to handle account creation logic
    ) {
    }
    //public function __invoke(CreateUserSchema $createUserSchema): ToolResult
    public function __invoke(AccountSetup $accountSetup): ToolResult
    {
//        if (!$user = $this->userRepository->findOneBy(['code' => $as->root])) {
//            $user = new User(
//                $as->root,
//                $as->approx,
//            );
//            $this->entityManager->persist($user);
//            $new = true;
//        }
//        $user
//            ->setThumbCallbackUrl($as->thumbCallbackUrl)
//            ->setMediaCallbackUrl($as->mediaCallbackUrl);
//        $this->entityManager->flush();
//        // Use serialization groups to prevent circular reference
//        $userData = $this->normalizer->normalize($user, 'object', ['groups' => ['user.read']]);
//        return [$userData, $new];

        /** @var User $user */
        [$user,$new] = $this->apiService->accountSetup($accountSetup);
//        $resource = $this->resourceRegistry->getResource('database://user/test');

        $content = [new TextToolResult(sprintf('User created successfully: id=%d', $user->getId()))];

//        // 2) Structured JSON your client can map to a DTO
//        $payload = [
//            'user' => [
//                'id'       => $user->id,
//                'email'    => $user->email,
//                'username' => $user->username,
//                // …anything else you want to strongly-type on the client
//            ],
//        ];
//
        // Many MCP clients expect the structured payload under "structuredContent".
        // The bundle’s ToolResult supports extra named args; if your version doesn’t,
        // you can still put JSON text in TextToolResult as a fallback.

        return new ToolResult([
//            $resource,
            new TextToolResult('Account '.($new ? 'created' : 'updated').' successfully! ' . $user['approxImageCount'] . ' images expected.')]);
    }
}
