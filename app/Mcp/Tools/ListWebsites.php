<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DefinesMcpSchema;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List active websites available to the authenticated Peekchimp workspace.')]
#[IsReadOnly]
#[IsIdempotent]
class ListWebsites extends Tool
{
    use DefinesMcpSchema;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Authentication is required.');
        }

        $websites = $user->workspaceOwnerUser()->projects()
            ->where('is_active', true)
            ->with(['domains', 'searchConsoleConnection'])
            ->orderBy('name')
            ->get()
            ->map(fn ($project): array => [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->name,
                'timezone' => (string) $project->timezone,
                'domains' => $project->domains->map(fn ($domain): array => [
                    'domain' => (string) $domain->domain,
                    'verified' => (bool) $domain->is_verified,
                ])->values()->all(),
                'searchConsole' => [
                    'connected' => $project->searchConsoleConnection !== null,
                    'status' => $project->searchConsoleConnection?->status,
                    'dataThrough' => $project->searchConsoleConnection?->data_through?->toDateString(),
                ],
            ])->values()->all();

        return Response::structured([
            'status' => 'ok',
            'websites' => $websites,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->required(),
            'websites' => $schema->array()
                ->items($schema->object([
                    'id' => $schema->integer(),
                    'name' => $schema->string(),
                    'timezone' => $schema->string(),
                    'domains' => $schema->array(),
                    'searchConsole' => $schema->object(),
                ])),
        ];
    }
}
