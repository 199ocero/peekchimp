<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\SearchConsole\SearchConsoleAnalyticsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Identify aggregate organic-search landing-page opportunities using Search Console and Google-organic visits.')]
#[IsReadOnly]
#[IsIdempotent]
class GetOrganicSearchOpportunities extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly SearchConsoleAnalyticsService $searchConsoleAnalytics) {}

    /**
     * Handle the tool request.
     */
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

        $report = $this->searchConsoleAnalytics->report(
            $project,
            $range['from']->toDateString(),
            $range['to']->toDateString(),
        );

        if (($report['status'] ?? null) === 'not_connected') {
            return Response::structured([
                'status' => 'not_connected',
                'range' => [
                    'key' => $range['key'],
                    'label' => $range['label'],
                    'from' => $range['from']->toDateString(),
                    'to' => $range['to']->toDateString(),
                ],
                'data' => ['opportunities' => [], 'insights' => []],
            ]);
        }

        return Response::structured([
            'status' => (string) ($report['status'] ?? 'unknown'),
            'range' => [
                'key' => $range['key'],
                'label' => $range['label'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'data' => [
                'organicFunnel' => $report['organicFunnel'] ?? null,
                'opportunities' => array_slice($report['landingPages'] ?? [], 0, 20),
                'topQueries' => array_slice($report['queries'] ?? [], 0, 25),
                'insights' => $report['insights'] ?? [],
            ],
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->reportInputSchema($schema);
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->reportOutputSchema($schema);
    }
}
