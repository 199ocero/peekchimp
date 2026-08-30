<?php

namespace App\Mcp\Tools;

use App\Actions\Websites\UpdateGrowthContextAction;
use App\Mcp\Concerns\DefinesMcpSchema;
use App\Mcp\Concerns\UsesMcpProject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Save confirmed, non-sensitive website growth context. Use only after the user approves the exact business details. Never use this tool for credentials or account settings.')]
#[IsDestructive(false)]
#[IsIdempotent]
class SaveGrowthContext extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly UpdateGrowthContextAction $updateGrowthContext) {}

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

        $validated = $request->validate([
            'audience' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'products_services' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'value_proposition' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'brand_voice' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'primary_conversion_goals' => ['sometimes', 'nullable', 'array', 'max:10'],
            'primary_conversion_goals.*' => ['required', 'string', 'max:200'],
        ]);
        $fields = ['audience', 'products_services', 'value_proposition', 'brand_voice', 'primary_conversion_goals'];
        $growthContext = array_intersect_key($validated, array_flip($fields));

        if ($growthContext === []) {
            return Response::error('Provide at least one growth context field to save.');
        }

        foreach (['audience', 'products_services', 'value_proposition', 'brand_voice'] as $field) {
            if (array_key_exists($field, $growthContext)) {
                $growthContext[$field] = trim((string) $growthContext[$field]);
            }
        }
        if (array_key_exists('primary_conversion_goals', $growthContext)) {
            $goals = [];

            foreach ($growthContext['primary_conversion_goals'] ?? [] as $goal) {
                if (is_string($goal) && trim($goal) !== '') {
                    $goals[] = trim($goal);
                }
            }

            $growthContext['primary_conversion_goals'] = $goals;
        }

        $project = $this->updateGrowthContext->handle($project, $growthContext);

        return Response::structured([
            'status' => 'updated',
            'range' => null,
            'data' => [
                'context' => $project->growthContext(),
                'settingsUrl' => route('websites.settings.edit', $project).'#growth-context',
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
            'audience' => $schema->string()->nullable()->description('Who the website serves and what those people care about.'),
            'products_services' => $schema->string()->nullable()->description('The products, services, pricing model, and differentiators.'),
            'value_proposition' => $schema->string()->nullable()->description('Why visitors should choose this business and the outcome it promises.'),
            'brand_voice' => $schema->string()->nullable()->description('The desired tone and language for recommendations.'),
            'primary_conversion_goals' => $schema->array()->items($schema->string())->max(10)->nullable()->description('Up to ten plain-language business outcomes, such as Book a demo.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }
}
