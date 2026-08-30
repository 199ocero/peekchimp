<?php

namespace App\Services\Ai;

use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Server\Tool as McpTool;
use Stringable;

class ApprovableProjectScopedMcpTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    private readonly McpServerTool $tool;

    public function __construct(
        private readonly Project $project,
        McpTool $tool,
    ) {
        $this->tool = new McpServerTool($tool);
    }

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->tool->handle(new Request([
            ...$request->toArray(),
            'project_id' => $this->project->getKey(),
        ], $request->toolCallId()));
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        $toolSchema = $this->tool->schema($schema);
        unset($toolSchema['project_id']);

        return $toolSchema;
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required('Approve the exact website setup change before Peekchimp applies it.');
    }
}
