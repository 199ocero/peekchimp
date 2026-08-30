<?php

namespace App\Mcp\Tools;

use App\Actions\Websites\QueueWebsiteCrawlAction;
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
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Queue a crawl of a verified website after the user approves it. The crawl reads public website pages and never publishes changes.')]
#[IsDestructive(false)]
#[IsOpenWorld]
class StartWebsiteCrawl extends Tool
{
    use DefinesMcpSchema;
    use UsesMcpProject;

    public function __construct(private readonly QueueWebsiteCrawlAction $queueWebsiteCrawl) {}

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
        if (! $project->domains()->where('is_verified', true)->exists()) {
            return Response::error('Verify the website and tracker before starting a crawl.');
        }

        $queued = $this->queueWebsiteCrawl->handle($project);

        return Response::structured([
            'status' => $queued ? 'queued' : 'already_in_progress',
            'range' => null,
            'data' => [
                'settingsUrl' => route('websites.settings.edit', $project).'#website-crawl',
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
        return $this->projectInputSchema($schema);
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->structuredOutputSchema($schema);
    }
}
