<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Services\Mcp\GrowthContextService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return the website audience, offer, positioning, voice, conversion goals, observed homepage signals, and crawl freshness.')]
#[IsReadOnly]
#[IsIdempotent]
class GetWebsiteContext extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly GrowthContextService $context) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $project = $this->project($request);
        if ($project instanceof Response) {
            return $project;
        }

        $data = $this->context->get($project);

        return Response::structured(['status' => $data['status'], 'range' => null, 'data' => $data]);
    }

    /** @return array<string, Type> */
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
