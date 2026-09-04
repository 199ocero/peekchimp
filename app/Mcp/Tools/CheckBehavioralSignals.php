<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Analytics\BrowserSignalAnalyticsService;
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

#[Description('Return privacy-safe behavioral signal totals and top aggregate details by page, signal type, element, or endpoint for a reporting range.')]
#[IsReadOnly]
#[IsIdempotent]
class CheckBehavioralSignals extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(private readonly BrowserSignalAnalyticsService $signals) {}

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
            'data' => $this->signals->collectionStatus($project, $range['from'], $range['to']),
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->reportInputSchema($schema);
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->required(),
            'range' => $schema->object([
                'key' => $schema->string(),
                'label' => $schema->string(),
                'from' => $schema->string(),
                'to' => $schema->string(),
            ])->required(),
            'data' => $schema->object([
                'status' => $schema->string()->required(),
                'enabled' => $schema->boolean()->required(),
                'stored' => $schema->boolean()->required(),
                'signalCount' => $schema->integer()->required(),
                'sessionCount' => $schema->integer()->required(),
                'pageviewSessions' => $schema->integer()->required(),
                'eventTypes' => $schema->object()->required(),
                'signalDetails' => $schema->array()->items($schema->object([
                    'page' => $schema->string()->nullable(),
                    'signal' => $schema->string()->required(),
                    'count' => $schema->integer()->required(),
                    'sessions' => $schema->integer()->required(),
                    'element' => $schema->string()->nullable(),
                    'elementKey' => $schema->string()->nullable(),
                    'tag' => $schema->string()->nullable(),
                    'text' => $schema->string()->nullable(),
                    'href' => $schema->string()->nullable(),
                    'id' => $schema->string()->nullable(),
                    'name' => $schema->string()->nullable(),
                    'kind' => $schema->string()->nullable(),
                    'endpoint' => $schema->string()->nullable(),
                    'method' => $schema->string()->nullable(),
                    'status' => $schema->integer()->nullable(),
                    'errorType' => $schema->string()->nullable(),
                    'scriptPath' => $schema->string()->nullable(),
                    'destinationHost' => $schema->string()->nullable(),
                    'fileExtension' => $schema->string()->nullable(),
                ]))->required(),
                'signalDetailLimit' => $schema->integer()->required(),
                'lastSignalAt' => $schema->string()->nullable(),
                'evidenceRef' => $schema->string()->required(),
            ])->required(),
        ];
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
