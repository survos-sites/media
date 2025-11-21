# Use JSON RPC MCP API to handle requests and respond back with results.

Mcp models will mostly be received with the Sais Bundle and / or should be added via the bundle for schemas consistency.

# Adding a tool endpoint

add the tool php file to the src/Mcp/Tools directory.
# The tool class should be annotated with `#[AsTool]` and implement the `__invoke` method.

### The account setup example:
```php
<?php
namespace App\Mcp\Tools;

use App\Service\ApiService;
use Ecourty\McpServerBundle\Attribute\AsTool;
use Ecourty\McpServerBundle\Attribute\ToolAnnotations;
use Ecourty\McpServerBundle\IO\TextToolResult;
use Ecourty\McpServerBundle\IO\ToolResult;
use Survos\SaisBundle\Model\AccountSetup;

#[AsTool(
    name: 'create_account', # Unique identifier for the tool, used by clients to call it
    description: 'Creates a new account (with unique root) in the system', # This description is used by LLMs to understand the tool s purpose
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
        private readonly ApiService $apiService, // Inject the ApiService to handle account creation logic
    ) {
    }
    //public function __invoke(CreateUserSchema $createUserSchema): ToolResult
    public function __invoke(AccountSetup $accountSetup): ToolResult
    {
        [$user,$new] = $this->apiService->accountSetup($accountSetup);
        return new ToolResult([new TextToolResult('Account '.($new ? 'created' : 'updated').' successfully! ' . $user['approxImageCount'] . ' images expected.')]);
    }
}

?>
```
