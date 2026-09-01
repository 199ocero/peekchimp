<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Analytics\FunnelAnalyticsService;
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

#[Description('Explain the largest aggregate funnel drop-off and the segments concentrated in it.')]
#[IsReadOnly]
#[IsIdempotent]
class InvestigateFunnel extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly FunnelAnalyticsService $funnels) {}

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
        $funnelId = $request->get('funnel_id');
        if (! is_numeric($funnelId) || (int) $funnelId < 1) {
            return Response::error('A valid funnel_id is required.');
        }
        $funnel = $project->funnels()->whereKey((int) $funnelId)->where('is_active', true)->with('steps')->first();
        if ($funnel === null) {
            return Response::error('The requested funnel is not available to this account.');
        }
        $data = $this->funnels->investigate($funnel, $range['from'], $range['to']);

        return Response::structured([
            'status' => 'ok',
            'range' => $this->rangeData($range),
            'data' => ['funnel' => ['id' => (int) $funnel->getKey(), 'name' => $funnel->name, ...$data]],
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [...$this->reportInputSchema($schema), 'funnel_id' => $schema->integer()->required()->description('The active funnel ID returned by get-conversion-performance.')];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->reportOutputSchema($schema);
    }

    /**
     * @param  array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable}  $range
     * @return array<string, string>
     */
    private function rangeData(array $range): array
    {
        return ['key' => $range['key'], 'label' => $range['label'], 'from' => $range['from']->toDateString(), 'to' => $range['to']->toDateString()];
    }
}
