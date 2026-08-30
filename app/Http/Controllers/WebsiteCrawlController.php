<?php

namespace App\Http\Controllers;

use App\Actions\Websites\QueueWebsiteCrawlAction;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WebsiteCrawlController extends Controller
{
    public function __invoke(Project $project, QueueWebsiteCrawlAction $queueWebsiteCrawl): RedirectResponse
    {
        Gate::authorize('manage', $project);

        $queued = $queueWebsiteCrawl->handle($project);

        Inertia::flash('toast', [
            'type' => $queued ? 'success' : 'info',
            'message' => $queued
                ? 'Website crawl queued.'
                : 'A website crawl is already in progress.',
        ]);

        return to_route('websites.settings.edit', $project);
    }
}
