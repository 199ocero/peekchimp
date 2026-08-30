<?php

namespace App\Mcp\Tools;

use App\Actions\Goals\CreateGoalAction;
use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
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

#[Description('Create one confirmed conversion goal for an event or URL. Use only after the user approves the exact goal definition.')]
#[IsDestructive(false)]
#[IsIdempotent]
class CreateGoal extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly CreateGoalAction $createGoal) {}

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

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(['event', 'url'])],
            'event_name' => ['nullable', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/', Rule::requiredIf(fn (): bool => $request->get('type') === 'event')],
            'path' => ['nullable', 'string', 'max:2048', Rule::requiredIf(fn (): bool => $request->get('type') === 'url')],
            'path_operator' => ['nullable', 'string', Rule::in(['exact', 'prefix'])],
            'property_match' => ['nullable', 'array', 'max:5'],
            'property_match.*' => ['nullable'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $result = $this->createGoal->handle($project, $data);
        $goal = $result['goal'];

        return Response::structured([
            'status' => $result['created'] ? 'created' : 'already_exists',
            'range' => null,
            'data' => [
                'goal' => [
                    'id' => (int) $goal->getKey(),
                    'name' => $goal->name,
                    'type' => $goal->type,
                    'eventName' => $goal->event_name,
                    'path' => $goal->path,
                    'pathOperator' => $goal->path_operator,
                    'propertyMatch' => $goal->property_match,
                    'isActive' => $goal->is_active,
                ],
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
            'name' => $schema->string()->required()->description('A clear goal name, such as Book a demo.'),
            'type' => $schema->string()->enum(['event', 'url'])->required()->description('Use event for a tracked event, or url for a page view such as a thank-you page.'),
            'event_name' => $schema->string()->nullable()->description('Required when type is event. Use a confirmed tracked event name.'),
            'path' => $schema->string()->nullable()->description('Required when type is url. Use a website path beginning with /.'),
            'path_operator' => $schema->string()->enum(['exact', 'prefix'])->default('exact')->description('How an URL goal matches its path.'),
            'property_match' => $schema->object()->nullable()->description('Optional event-property values to match. Keep this empty unless the user confirms them.'),
            'is_active' => $schema->boolean()->default(true)->description('Whether the goal should be active immediately.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }
}
