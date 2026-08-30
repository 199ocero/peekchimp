<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Analytics\GoalAnalyticsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Return aggregate goal and funnel conversion performance for a website.')]
#[IsReadOnly]
#[IsIdempotent]
class GetConversionPerformance extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(
        private readonly GoalAnalyticsService $goalAnalytics,
        private readonly FunnelAnalyticsService $funnelAnalytics,
    ) {}

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

        $goals = $project->goals()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn ($goal): array => [
                'id' => (int) $goal->getKey(),
                'name' => (string) $goal->name,
                ...$this->goalAnalytics->summary($goal, $range['from'], $range['to']),
            ])->values()->all();
        $funnels = $project->funnels()
            ->where('is_active', true)
            ->with('steps')
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->map(fn ($funnel): array => [
                'id' => (int) $funnel->getKey(),
                'name' => (string) $funnel->name,
                ...$this->funnelAnalytics->summary($funnel, $range['from'], $range['to']),
            ])->values()->all();

        return Response::structured([
            'status' => 'ok',
            'range' => [
                'key' => $range['key'],
                'label' => $range['label'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'data' => ['goals' => $goals, 'funnels' => $funnels],
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
