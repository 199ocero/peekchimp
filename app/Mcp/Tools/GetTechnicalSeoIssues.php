<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Services\Mcp\TechnicalSeoService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return page or crawl-wide canonical, robots, heading, broken-link, structured-data, response, and crawlability issues.')]
#[IsReadOnly]
#[IsIdempotent]
class GetTechnicalSeoIssues extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly TechnicalSeoService $technicalSeo) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $project = $this->project($request);
        if ($project instanceof Response) {
            return $project;
        }
        $path = $request->get('path');
        $path = is_string($path) && $path !== '' ? $path : null;
        if ($path !== null && ! str_starts_with($path, '/')) {
            return Response::error('The path must begin with /.');
        }
        $limit = (int) $request->get('limit', 50);
        if ($limit < 1 || $limit > 100) {
            return Response::error('The limit must be between 1 and 100.');
        }
        $data = $this->technicalSeo->issues($project, $path, $limit);

        return Response::structured(['status' => $data['status'], 'range' => null, 'data' => $data]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->projectInputSchema($schema),
            'path' => $schema->string()->nullable()->description('Optional page path. Omit for a crawl-wide audit.'),
            'limit' => $schema->integer()->default(50)->description('Maximum issues, from 1 to 100.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }
}
