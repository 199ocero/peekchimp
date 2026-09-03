<?php

namespace App\Mcp\Tools;

use App\Actions\Goals\UpdateGoalAction;
use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use App\Models\Goal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update one confirmed conversion goal. Use only after the user approves the exact changes.')]
#[IsDestructive(false)]
#[IsIdempotent]
class UpdateGoal extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly UpdateGoalAction $updateGoal) {}

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

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('goals')->where('project_id', $project->getKey())->ignore($goal->getKey())],
            'type' => ['sometimes', 'required', 'string', Rule::in(['event', 'url'])],
            'event_name' => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/'],
            'path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'path_operator' => ['sometimes', 'nullable', 'string', Rule::in(['exact', 'prefix'])],
            'property_match' => ['sometimes', 'nullable', 'array', 'max:5'],
            'property_match.*' => ['nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) {
            return Response::error('Provide at least one goal field to update.');
        }

        $type = (string) ($data['type'] ?? $goal->type);
        if ($type === 'event' && blank($data['event_name'] ?? $goal->event_name)) {
            return Response::error('An event_name is required for event goals.');
        }
        if ($type === 'url' && blank($data['path'] ?? $goal->path)) {
            return Response::error('A path is required for URL goals.');
        }

        $goal = $this->updateGoal->handle($goal, $data);

        return Response::structured([
            'status' => 'updated',
            'range' => null,
            'data' => [
                'goal' => $this->goalData($goal),
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
            'goal_id' => $schema->integer()->required()->description('The goal ID returned by conversion performance or goal creation.'),
            'name' => $schema->string()->description('A new display name for the goal.'),
            'type' => $schema->string()->enum(['event', 'url'])->description('Change the goal to an event or URL goal.'),
            'event_name' => $schema->string()->nullable()->description('The tracked event name. Required when the resulting goal type is event.'),
            'path' => $schema->string()->nullable()->description('The website path. Required when the resulting goal type is url.'),
            'path_operator' => $schema->string()->enum(['exact', 'prefix'])->description('How an URL goal matches its path.'),
            'property_match' => $schema->object()->nullable()->description('Optional event-property values to match. Pass null to clear them.'),
            'is_active' => $schema->boolean()->description('Whether the goal is active.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }

    /** @return array<string, mixed> */
    private function goalData(Goal $goal): array
    {
        return [
            'id' => (int) $goal->getKey(),
            'name' => $goal->name,
            'type' => $goal->type,
            'eventName' => $goal->event_name,
            'path' => $goal->path,
            'pathOperator' => $goal->path_operator,
            'propertyMatch' => $goal->property_match,
            'isActive' => $goal->is_active,
        ];
    }
}
