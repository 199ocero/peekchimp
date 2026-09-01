<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Mcp\Concerns\UsesMcpReportRange;
use App\Services\Analytics\ProductInvestigationService;
use App\Services\Websites\WebsiteUrlNormalizer;
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

#[Description('Compare a reporting period with the preceding equal period and rank aggregate changes.')]
#[IsReadOnly]
#[IsIdempotent]
class InvestigateChange extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;
    use UsesMcpReportRange;

    public function __construct(
        private readonly ProductInvestigationService $investigation,
        private readonly WebsiteUrlNormalizer $normalizer,
    ) {}

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
        if ($path !== null && (! is_string($path) || ! str_starts_with($path, '/'))) {
            return Response::error('The optional path must begin with /.');
        }
        $path = is_string($path) ? $this->normalizer->normalizePath($path) : null;

        return Response::structured([
            'status' => 'ok',
            'range' => $this->rangeData($range),
            'data' => $this->investigation->investigateChange($project, $range['from'], $range['to'], $path),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [...$this->reportInputSchema($schema), 'path' => $schema->string()->nullable()->description('Optional website path to scope the comparison.')];
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
