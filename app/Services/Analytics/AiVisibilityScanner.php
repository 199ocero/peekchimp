<?php

namespace App\Services\Analytics;

use App\Jobs\CrawlWebsitePage;
use App\Jobs\FinalizeWebsiteCrawl;
use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Services\Websites\WebsiteCrawlDiscovery;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class AiVisibilityScanner
{
    public function __construct(private readonly WebsiteCrawlDiscovery $discovery) {}

    public function scan(Project $project, AiVisibilityScan $scan): void
    {
        $scan->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
        ])->save();

        $discovery = $this->discovery->discover($project);
        if ($discovery['error'] !== null) {
            $scan->forceFill([
                'status' => 'failed',
                'findings' => $discovery['findings'],
                'error' => $discovery['error'],
                'completed_at' => now(),
            ])->save();

            return;
        }

        $scan->forceFill(['findings' => $discovery['findings']])->save();
        $projectId = (int) $project->getKey();
        $scanId = (int) $scan->getKey();
        $jobs = array_map(
            fn (string $url): CrawlWebsitePage => new CrawlWebsitePage($projectId, $scanId, $url),
            $discovery['urls'],
        );

        Bus::batch($jobs)
            ->name('Website crawl '.$projectId.' / '.$scanId)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($projectId, $scanId): void {
                FinalizeWebsiteCrawl::dispatch($projectId, $scanId);
            })
            ->onQueue('crawl')
            ->dispatch();
    }
}
