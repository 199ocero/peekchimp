<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Mcp\ContentRecommendationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Build an evidence packet for a content brief, including queries, intent signals, coverage gaps, internal links, CTA goals, and client-model instructions.')]
#[IsReadOnly]
#[IsIdempotent]
class BuildContentBrief extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly ContentRecommendationService $recommendations) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $project = $this->project($request);
        if ($project instanceof Response) {
            return $project;
        }
        $range = $this->reportRange($request, $project);
        if ($range instanceof Response) {
            return $range;
        }
        $path = $request->get('path');
        $query = $request->get('primary_query');
        $path = is_string($path) && $path !== '' ? $path : null;
        $query = is_string($query) && $query !== '' ? $query : null;
        if ($path === null && $query === null) {
            return Response::error('Provide a path or primary_query to build a content brief.');
        }
        if ($path !== null && ! str_starts_with($path, '/')) {
            return Response::error('The path must begin with /.');
        }
        $data = $this->recommendations->brief($project, $path, $query, $range['from'], $range['to']);

        return Response::structured(['status' => $data['status'], 'range' => $this->rangeData($range), 'data' => $data]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->reportInputSchema($schema),
            'path' => $schema->string()->nullable()->description('An existing page path to improve.'),
            'primary_query' => $schema->string()->nullable()->description('The primary search query for new or existing content.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }

    /**
     * @param  array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable, dashboardFilters: array<string, string>}  $range
     * @return array<string, string>
     */
    private function rangeData(array $range): array
    {
        return ['key' => $range['key'], 'label' => $range['label'], 'from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()];
    }
}
