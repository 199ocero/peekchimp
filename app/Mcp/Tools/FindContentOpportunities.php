<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Mcp\ContentOpportunityService;
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

#[Description('Rank missing-topic hypotheses, high-impression queries, weak landing pages, query cannibalization, and content decay using aggregate evidence.')]
#[IsReadOnly]
#[IsIdempotent]
class FindContentOpportunities extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly ContentOpportunityService $opportunities) {}

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
        $limit = (int) $request->get('limit', 20);
        if ($limit < 1 || $limit > 50) {
            return Response::error('The limit must be between 1 and 50.');
        }
        $data = $this->opportunities->find($project, $range['from'], $range['to'], $limit);

        return Response::structured(['status' => $data['status'], 'range' => $this->rangeData($range), 'data' => $data]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [...$this->reportInputSchema($schema), 'limit' => $schema->integer()->default(20)->description('Maximum opportunities, from 1 to 50.')];
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
