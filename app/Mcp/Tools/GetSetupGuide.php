<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Models\User;
use App\Services\Mcp\SetupGuidanceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return a live, plain-language setup checklist with safe status, exact settings links, and recommended next actions for a website. Never returns credentials or other secrets.')]
#[IsReadOnly]
#[IsIdempotent]
class GetSetupGuide extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly SetupGuidanceService $guidance) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $project = $this->project($request);
        if ($project instanceof Response) {
            return $project;
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Authentication is required.');
        }

        $guide = $this->guidance->get($project, $user);

        return Response::structured(['status' => $guide['status'], 'range' => null, 'data' => $guide]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->projectInputSchema($schema);
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }
}
