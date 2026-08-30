<?php

namespace App\Services\Websites;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Models\WebsitePageSnapshot;
use Illuminate\Support\Collection;

class WebsiteSnapshotService
{
    public function __construct(private readonly WebsiteUrlNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $fetch
     * @param  array<string, mixed>  $extracted
     */
    public function store(Project $project, AiVisibilityScan $scan, string $url, array $fetch, array $extracted): WebsitePageSnapshot
    {
        $normalizedPath = $this->normalizer->normalizePath((string) ($fetch['final_url'] ?? $url));

        $urlHash = hash('sha256', $url);

        return WebsitePageSnapshot::query()->updateOrCreate([
            'ai_visibility_scan_id' => $scan->getKey(),
            'url_hash' => $urlHash,
        ], [
            'project_id' => $project->getKey(),
            'url' => $url,
            'normalized_path' => $normalizedPath,
            'http_status' => $fetch['status'] ?? null,
            'content_type' => $fetch['content_type'] ?? null,
            'title' => $extracted['title'] ?? null,
            'meta_description' => $extracted['meta_description'] ?? null,
            'canonical_url' => $extracted['canonical_url'] ?? null,
            'robots_directives' => $extracted['robots_directives'] ?? [],
            'headings' => $extracted['headings'] ?? [],
            'links' => $extracted['links'] ?? [],
            'cta_candidates' => $extracted['cta_candidates'] ?? [],
            'structured_data' => $extracted['structured_data'] ?? [],
            'main_content' => $extracted['main_content'] ?? '',
            'word_count' => $extracted['word_count'] ?? 0,
            'content_hash' => $extracted['content_hash'] ?? null,
            'response_time_ms' => $fetch['response_time_ms'] ?? null,
            'response_bytes' => $fetch['response_bytes'] ?? 0,
            'redirect_chain' => $fetch['redirect_chain'] ?? [],
            'error' => $fetch['error'] ?? null,
            'crawled_at' => now(),
        ]);
    }

    public function latestForPath(Project $project, string $path): ?WebsitePageSnapshot
    {
        return $project->pageSnapshots()
            ->where('normalized_path', $this->normalizer->normalizePath($path))
            ->latest('crawled_at')
            ->first();
    }

    /** @return Collection<int, WebsitePageSnapshot> */
    public function latest(Project $project): Collection
    {
        return $project->pageSnapshots()
            ->latest('crawled_at')
            ->get()
            ->unique('url_hash')
            ->values();
    }

    /** @return array{last_crawled_at: string|null, stale: bool, age_days: int|null, page_count: int} */
    public function freshness(Project $project): array
    {
        $snapshots = $this->latest($project);
        $lastCrawledAt = $snapshots->max('crawled_at');

        return [
            'last_crawled_at' => $lastCrawledAt?->toIso8601String(),
            'stale' => $lastCrawledAt === null || $lastCrawledAt->lt(now()->subDays((int) config('analytics.website_crawl.stale_after_days', 8))),
            'age_days' => $lastCrawledAt?->diffInDays(now()),
            'page_count' => $snapshots->count(),
        ];
    }

    public function prune(Project $project): void
    {
        $project->pageSnapshots()
            ->where('crawled_at', '<', now()->subDays((int) config('analytics.website_crawl.retention_days', 90)))
            ->delete();

        $keep = max(1, (int) config('analytics.website_crawl.versions_per_url', 2));
        $project->pageSnapshots()
            ->latest('crawled_at')
            ->get(['id', 'url_hash'])
            ->groupBy('url_hash')
            ->each(function (Collection $versions) use ($keep): void {
                WebsitePageSnapshot::query()
                    ->whereIn('id', $versions->skip($keep)->pluck('id'))
                    ->delete();
            });
    }
}
