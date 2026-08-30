<?php

namespace App\Jobs;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Services\Websites\WebsiteHtmlExtractor;
use App\Services\Websites\WebsitePageFetcher;
use App\Services\Websites\WebsiteSnapshotService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class CrawlWebsitePage implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 2;

    public int $timeout = 25;

    public function __construct(
        public readonly int $projectId,
        public readonly int $scanId,
        public readonly string $url,
    ) {
        $this->onQueue('crawl');
    }

    public function handle(
        WebsitePageFetcher $fetcher,
        WebsiteHtmlExtractor $extractor,
        WebsiteSnapshotService $snapshots,
    ): void {
        $project = Project::query()->find($this->projectId);
        $scan = AiVisibilityScan::query()->find($this->scanId);
        if ($project === null || $scan === null || $scan->status === 'failed') {
            return;
        }

        $fetch = $fetcher->fetch($project, $this->url);
        $contentType = Str::lower((string) ($fetch['content_type'] ?? ''));
        $isHtml = $contentType === '' || Str::contains($contentType, ['text/html', 'application/xhtml+xml']);
        if (! $isHtml && $fetch['error'] === null) {
            $fetch['error'] = 'The response is not an HTML document.';
            $fetch['body'] = '';
        }

        $extracted = $fetch['body'] === ''
            ? $extractor->extract('', $this->url)
            : $extractor->extract($fetch['body'], $fetch['final_url']);
        $snapshots->store($project, $scan, $this->url, $fetch, $extracted);
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['crawl', 'project:'.$this->projectId, 'scan:'.$this->scanId];
    }
}
