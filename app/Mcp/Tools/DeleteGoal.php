<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Models\Goal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Permanently delete one confirmed conversion goal and its conversion history. Use only after the user approves the exact deletion.')]
#[IsDestructive]
#[IsIdempotent]
class DeleteGoal extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $project = $this->project($request);
        if ($project instanceof Response) {
            return $project;
        }
        if (! $request->user()?->can('manage', $project)) {
            return Response::error('You are not allowed to update this website.');
        }

        $goalId = $request->validate(['goal_id' => ['required', 'integer', 'min:1']])['goal_id'];
        $goal = $project->goals()->find($goalId);
        if (! $goal instanceof Goal) {
            return Response::error('The requested goal is not available for this website.');
        }

        $deletedGoal = ['id' => (int) $goal->getKey(), 'name' => (string) $goal->name];
        $goal->delete();

        return Response::structured([
            'status' => 'deleted',
            'range' => null,
            'data' => [
                'goal' => $deletedGoal,
                'settingsUrl' => route('websites.goals.index', $project),
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
        return [
            ...$this->projectInputSchema($schema),
            'goal_id' => $schema->integer()->required()->description('The goal ID to permanently delete.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }
}
