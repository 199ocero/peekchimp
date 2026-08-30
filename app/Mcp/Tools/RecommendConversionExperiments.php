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

#[Description('Return prioritized CTA, landing-page, messaging, and funnel experiments with hypotheses, effort, impact, baselines, and success metrics.')]
#[IsReadOnly]
#[IsIdempotent]
class RecommendConversionExperiments extends Tool
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
        $path = is_string($path) && $path !== '' ? $path : null;
        if ($path !== null && ! str_starts_with($path, '/')) {
            return Response::error('The path must begin with /.');
        }
        $funnelId = $request->get('funnel_id');
        if ($funnelId !== null && (! is_numeric($funnelId) || (int) $funnelId < 1)) {
            return Response::error('The funnel_id must be a positive integer.');
        }
        $data = $this->recommendations->experiments($project, $path, $funnelId === null ? null : (int) $funnelId, $range['from'], $range['to']);

        return Response::structured(['status' => $data['status'], 'range' => $this->rangeData($range), 'data' => $data]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->reportInputSchema($schema),
            'path' => $schema->string()->nullable()->description('Optional landing-page path. Omit for site-wide selection.'),
            'funnel_id' => $schema->integer()->nullable()->description('Optional configured funnel ID. Omit to use the first active funnel.'),
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
