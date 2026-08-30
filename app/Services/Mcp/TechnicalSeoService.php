<?php

namespace App\Services\Mcp;

use App\Models\Project;
use App\Services\Websites\WebsiteSnapshotService;
use App\Services\Websites\WebsiteUrlNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TechnicalSeoService
{
    public function __construct(
        private readonly WebsiteSnapshotService $snapshots,
        private readonly WebsiteUrlNormalizer $normalizer,
    ) {}

    /** @return array<string, mixed> */
    public function issues(Project $project, ?string $path = null, int $limit = 50): array
    {
        $pages = $this->snapshots->latest($project);
        if ($path !== null) {
            $normalizedPath = $this->normalizer->normalizePath($path);
            $pages = $pages->where('normalized_path', $normalizedPath)->values();
        }
        if ($pages->isEmpty()) {
            return [
                'status' => 'snapshot_required',
                'freshness' => $this->snapshots->freshness($project),
                'coverage' => ['pages' => 0],
                'issues' => [],
            ];
        }

        $issues = collect();
        $titles = $pages->filter(fn ($page): bool => $page->title !== null)->groupBy(fn ($page): string => Str::lower((string) $page->title));
        $descriptions = $pages->filter(fn ($page): bool => $page->meta_description !== null)->groupBy(fn ($page): string => Str::lower((string) $page->meta_description));
        $statuses = $pages->keyBy('normalized_path')->map->http_status;

        foreach ($pages as $page) {
            $path = $page->normalized_path;
            $evidence = 'snapshot:'.$path.'@'.$page->crawled_at->toIso8601String();
            if ($page->error !== null || $page->http_status === null || $page->http_status >= 400) {
                $issues->push($this->issue('crawlability', 'high', $path, $page->error ?? 'The page returned HTTP '.$page->http_status.'.', 'Make the page return a successful HTML response.', $evidence));
            }
            if ($page->title === null) {
                $issues->push($this->issue('title', 'high', $path, 'The page has no title.', 'Add a unique descriptive title.', $evidence));
            } elseif (Str::length($page->title) > 60) {
                $issues->push($this->issue('title', 'medium', $path, 'The title is longer than 60 characters.', 'Shorten the title while preserving its primary intent.', $evidence));
            }
            if ($page->meta_description === null) {
                $issues->push($this->issue('meta_description', 'medium', $path, 'The page has no meta description.', 'Add a concise description aligned with the page intent.', $evidence));
            }
            if ($page->canonical_url === null) {
                $issues->push($this->issue('canonical', 'medium', $path, 'The page has no canonical URL.', 'Add a self-referencing canonical unless another canonical is intentional.', $evidence));
            } elseif (Str::lower((string) parse_url($page->canonical_url, PHP_URL_HOST)) !== Str::lower((string) parse_url($page->url, PHP_URL_HOST))) {
                $issues->push($this->issue('canonical', 'high', $path, 'The canonical points to another host.', 'Verify the cross-domain canonical is intentional.', $evidence));
            } elseif ($this->normalizer->normalizePath($page->canonical_url) !== $path) {
                $issues->push($this->issue('canonical', 'medium', $path, 'The canonical points to a different page on this website.', 'Verify that consolidating this page into the canonical target is intentional.', $evidence));
            }
            if (collect($page->robots_directives ?? [])->contains('noindex')) {
                $issues->push($this->issue('robots', 'high', $path, 'The page declares noindex.', 'Remove noindex if this page should appear in search.', $evidence));
            }
            $h1Count = collect($page->headings ?? [])->where('level', 1)->count();
            if ($h1Count === 0) {
                $issues->push($this->issue('headings', 'medium', $path, 'The page has no H1 heading.', 'Add one clear primary heading.', $evidence));
            } elseif ($h1Count > 1) {
                $issues->push($this->issue('headings', 'low', $path, 'The page has multiple H1 headings.', 'Use one primary H1 and organize subsections beneath it.', $evidence));
            }
            if (collect($page->structured_data)->contains(fn (array $item): bool => $item['valid'] === false)) {
                $issues->push($this->issue('structured_data', 'medium', $path, 'The page contains invalid JSON-LD.', 'Correct the malformed structured-data block.', $evidence));
            }
            if (($page->response_time_ms ?? 0) > 2000) {
                $issues->push($this->issue('performance', 'medium', $path, 'The crawler observed a response time above two seconds.', 'Reduce server response time and recheck the page.', $evidence));
            }
            if ($page->response_bytes > 512000) {
                $issues->push($this->issue('performance', 'low', $path, 'The server-returned HTML exceeds 500 KB.', 'Reduce HTML payload and duplicated markup.', $evidence));
            }
            foreach (collect($page->links ?? [])->where('internal', true) as $link) {
                $targetPath = $link['path'] ?? null;
                if (is_string($targetPath) && ($statuses->get($targetPath) ?? 200) >= 400) {
                    $issues->push($this->issue('broken_link', 'high', $path, 'An internal link points to a failed page: '.$targetPath.'.', 'Update or remove the broken internal link.', $evidence));
                }
            }
        }

        foreach ($titles->filter(fn (Collection $matches): bool => $matches->count() > 1) as $matches) {
            foreach ($matches as $page) {
                $issues->push($this->issue('duplicate_title', 'medium', $page->normalized_path, 'The title is duplicated on another crawled page.', 'Give each page a title that distinguishes its intent.', 'snapshot:'.$page->normalized_path.'@'.$page->crawled_at->toIso8601String()));
            }
        }
        foreach ($descriptions->filter(fn (Collection $matches): bool => $matches->count() > 1) as $matches) {
            foreach ($matches as $page) {
                $issues->push($this->issue('duplicate_description', 'low', $page->normalized_path, 'The meta description is duplicated on another crawled page.', 'Write a page-specific description.', 'snapshot:'.$page->normalized_path.'@'.$page->crawled_at->toIso8601String()));
            }
        }

        $severityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
        $issues = $issues->unique('id')->sortByDesc(fn (array $issue): int => $severityOrder[$issue['severity']] ?? 0)->take(min(100, max(1, $limit)))->values();

        return [
            'status' => $issues->isEmpty() ? 'ok' : 'issues_found',
            'freshness' => $this->snapshots->freshness($project),
            'coverage' => [
                'pages' => $pages->count(),
                'successfulPages' => $pages->filter(fn ($page): bool => $page->error === null && ($page->http_status ?? 500) < 400)->count(),
            ],
            'issues' => $issues->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function issue(string $type, string $severity, string $path, string $message, string $recommendation, string $evidence): array
    {
        return [
            'id' => sha1($type.'|'.$path.'|'.$message),
            'type' => $type,
            'severity' => $severity,
            'path' => $path,
            'message' => $message,
            'recommendation' => $recommendation,
            'evidence' => [$evidence],
        ];
    }
}
