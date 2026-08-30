<?php

namespace App\Actions\Websites;

use App\Jobs\RunAiVisibilityScan;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;

class QueueWebsiteCrawlAction
{
    public function handle(Project $project): bool
    {
        $lock = Cache::lock('website-crawl-queue-'.$project->getKey(), 5);

        if (! $lock->get()) {
            return false;
        }

        try {
            $isCrawlInProgress = $project->aiVisibilityScans()
                ->whereIn('status', ['queued', 'running'])
                ->exists();

            if ($isCrawlInProgress) {
                return false;
            }

            $scan = $project->aiVisibilityScans()->create(['status' => 'queued']);

            RunAiVisibilityScan::dispatch($project, $scan)->afterCommit();

            return true;
        } finally {
            $lock->release();
        }
    }
}
