<?php

namespace App\Jobs;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Services\Websites\WebsiteSnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FinalizeWebsiteCrawl implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(
        public readonly int $projectId,
        public readonly int $scanId,
    ) {
        $this->onQueue('crawl');
    }

    public function handle(WebsiteSnapshotService $snapshots): void
    {
        $project = Project::query()->find($this->projectId);
        $scan = AiVisibilityScan::query()->find($this->scanId);
        if ($project === null || $scan === null || $scan->status === 'failed') {
            return;
        }

        $pages = $scan->pageSnapshots()->orderBy('normalized_path')->get();
        $successful = $pages->filter(fn ($page): bool => $page->http_status !== null && $page->http_status < 400 && $page->error === null);
        $complete = $pages->count() > 0 && $successful->count() === $pages->count();
        $qualityChecks = $successful->sum(function ($page): int {
            $hasH1 = collect($page->headings)->contains(fn (array $heading): bool => $heading['level'] === 1);
            $validSchema = collect($page->structured_data)->contains(fn (array $item): bool => $item['valid']);

            return (int) ($page->title !== null)
                + (int) ($page->meta_description !== null)
                + (int) $hasH1
                + (int) $validSchema;
        });
        $maximumChecks = max(1, $successful->count() * 4);
        $findings = is_array($scan->findings) ? $scan->findings : [];
        $findings['crawl'] = [
            'pagesDiscovered' => (int) data_get($findings, 'discoveredPages', $pages->count()),
            'pagesCrawled' => $pages->count(),
            'pagesSuccessful' => $successful->count(),
            'pagesFailed' => $pages->count() - $successful->count(),
        ];
        $findings['pages'] = $pages->map(fn ($page): array => [
            'path' => $page->normalized_path,
            'status' => $page->http_status,
            'title' => $page->title !== null,
            'description' => $page->meta_description !== null,
            'structuredData' => collect($page->structured_data)->contains(fn (array $item): bool => $item['valid']),
            'error' => $page->error,
        ])->values()->all();

        $scan->forceFill([
            'status' => $complete ? 'completed' : 'partial',
            'score' => (int) round(($qualityChecks / $maximumChecks) * 100),
            'findings' => $findings,
            'error' => $complete ? null : 'Some pages could not be crawled. Available snapshots remain usable.',
            'completed_at' => now(),
        ])->save();

        $snapshots->prune($project);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['crawl', 'project:'.$this->projectId, 'scan:'.$this->scanId];
    }
}
