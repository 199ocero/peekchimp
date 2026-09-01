<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Analytics\ProductInvestigationService;
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

#[Description('Rank aggregate runtime, performance, interaction, and funnel friction worth investigating.')]
#[IsReadOnly]
#[IsIdempotent]
class FindFriction extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly ProductInvestigationService $investigation) {}

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

        return Response::structured([
            'status' => 'ok',
            'range' => $this->rangeData($range),
            'data' => $this->investigation->findFriction($project, $range['from'], $range['to']),
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
