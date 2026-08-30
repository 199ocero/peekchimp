<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Queries\Analytics\DashboardQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return aggregate traffic, engagement, source, page, and AI-referral analytics for a website.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAnalyticsOverview extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly DashboardQuery $dashboardQuery) {}

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

        $analytics = $this->dashboardQuery->run(
            $project,
            $range['dashboardFilters'],
            false,
            false,
        );

        return Response::structured([
            'status' => 'ok',
            'range' => [
                'key' => $range['key'],
                'label' => $range['label'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'data' => [
                'metrics' => $analytics['metrics'],
                'metricTrends' => $analytics['metricTrends'],
                'timeseries' => $analytics['timeseries'],
                'topPages' => array_slice($analytics['topPages'], 0, 10),
                'entryPages' => array_slice($analytics['entryPages'], 0, 10),
                'exitPages' => array_slice($analytics['exitPages'], 0, 10),
                'sources' => array_slice($analytics['sources'], 0, 10),
                'referrers' => array_slice($analytics['referrers'], 0, 10),
                'countryVisits' => $analytics['countryVisits'],
                'aiReferrals' => $analytics['aiReferrals'],
                'aiTraffic' => $analytics['aiTraffic'],
                'insights' => $analytics['insights'],
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
