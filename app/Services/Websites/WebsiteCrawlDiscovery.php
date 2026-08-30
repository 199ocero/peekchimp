<?php

namespace App\Services\Websites;

use App\Models\AnalyticsEvent;
use App\Models\Project;
use App\Models\SearchConsoleMetric;
use DOMDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebsiteCrawlDiscovery
{
    public function __construct(
        private readonly WebsitePageFetcher $fetcher,
        private readonly WebsiteHtmlExtractor $extractor,
        private readonly WebsiteUrlGuard $guard,
        private readonly WebsiteUrlNormalizer $normalizer,
    ) {}

    /** @return array{urls: array<int, string>, findings: array<string, mixed>, error: string|null} */
    public function discover(Project $project): array
    {
        $domain = $this->guard->verifiedDomains($project)->first();
        if (! is_string($domain)) {
            return ['urls' => [], 'findings' => [], 'error' => 'A verified domain is required.'];
        }

        $baseUrl = 'https://'.$domain;
        $robots = $this->fetcher->fetch($project, $baseUrl.'/robots.txt', 'text/plain');
        $robotsText = $robots['status'] === 200 ? $robots['body'] : '';
        $disallowed = $this->disallowedPaths($robotsText);
        $sitemapUrls = $this->sitemapLocations($robotsText, $baseUrl);
        if ($sitemapUrls === []) {
            $sitemapUrls = [$baseUrl.'/sitemap.xml'];
        }

        $sitemapPages = [];
        $sitemapStatus = null;
        $pendingSitemaps = array_slice($sitemapUrls, 0, 5);
        $visitedSitemaps = [];
        while ($pendingSitemaps !== [] && count($visitedSitemaps) < 10) {
            $sitemapUrl = array_shift($pendingSitemaps);
            if (in_array($sitemapUrl, $visitedSitemaps, true)) {
                continue;
            }

            $visitedSitemaps[] = $sitemapUrl;
            $sitemap = $this->fetcher->fetch($project, $sitemapUrl, 'application/xml, text/xml');
            $sitemapStatus ??= $sitemap['status'];
            if ($sitemap['status'] === 200) {
                foreach ($this->xmlLocations($sitemap['body']) as $location) {
                    $locationPath = Str::lower((string) parse_url($location, PHP_URL_PATH));
                    if (Str::endsWith($locationPath, '.xml') && $this->guard->allows($project, $location)) {
                        $pendingSitemaps[] = $location;
                    } else {
                        $sitemapPages[] = $location;
                    }
                }
            }
        }

        $home = $this->fetcher->fetch($project, $baseUrl.'/');
        $homeExtraction = $home['status'] === 200
            ? $this->extractor->extract($home['body'], $home['final_url'])
            : ['links' => []];
        $homeLinks = $homeExtraction['links'] ?? [];
        if (! is_array($homeLinks)) {
            $homeLinks = [];
        }

        $llms = $this->fetcher->fetch($project, $baseUrl.'/llms.txt', 'text/plain');
        $candidates = collect([$baseUrl.'/'])
            ->merge($this->highValueUrls($project, $baseUrl))
            ->merge($sitemapPages)
            ->merge(collect($homeLinks)->where('internal', true)->pluck('url'))
            ->map(fn (mixed $url): ?string => is_string($url) ? $this->normalizer->canonicalize($url) : null)
            ->filter(fn (mixed $url): bool => is_string($url)
                && $this->guard->allows($project, $url)
                && $this->isCrawlablePath($url, $disallowed))
            ->unique()
            ->take((int) config('analytics.website_crawl.max_pages', 100))
            ->values();

        return [
            'urls' => $candidates->all(),
            'findings' => [
                'robots' => ['available' => $robots['status'] === 200, 'status' => $robots['status']],
                'sitemap' => ['available' => $sitemapStatus === 200, 'status' => $sitemapStatus],
                'homepage' => ['available' => $home['status'] === 200, 'status' => $home['status']],
                'llmsTxt' => ['available' => $llms['status'] === 200, 'status' => $llms['status']],
                'discoveredPages' => $candidates->count(),
            ],
            'error' => $candidates->isEmpty() ? 'No crawlable HTML pages were discovered.' : null,
        ];
    }

    /** @return Collection<int, string> */
    private function highValueUrls(Project $project, string $baseUrl): Collection
    {
        $analyticsPaths = AnalyticsEvent::query()
            ->where('project_id', $project->getKey())
            ->where('event_name', 'page_view')
            ->whereNotNull('path')
            ->selectRaw('path, COUNT(*) as pageviews')
            ->groupBy('path')
            ->orderByDesc('pageviews')
            ->limit(30)
            ->pluck('path');
        $searchPaths = SearchConsoleMetric::query()
            ->where('project_id', $project->getKey())
            ->where('dimension_type', 'page')
            ->whereNotNull('normalized_path')
            ->selectRaw('normalized_path, SUM(impressions) as total_impressions')
            ->groupBy('normalized_path')
            ->orderByDesc('total_impressions')
            ->limit(30)
            ->pluck('normalized_path');

        return $analyticsPaths->merge($searchPaths)
            ->map(fn (mixed $path): ?string => is_string($path) ? $this->normalizer->absolute($baseUrl.'/', $path) : null)
            ->filter(fn (mixed $url): bool => is_string($url))
            ->values();
    }

    /** @return array<int, string> */
    private function sitemapLocations(string $robots, string $baseUrl): array
    {
        return collect(preg_split('/\R/', $robots) ?: [])
            ->filter(fn (string $line): bool => Str::startsWith(Str::lower(trim($line)), 'sitemap:'))
            ->map(fn (string $line): ?string => $this->normalizer->absolute($baseUrl.'/', trim(Str::after($line, ':'))))
            ->filter(fn (mixed $url): bool => is_string($url))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function xmlLocations(string $xml): array
    {
        if ($xml === '' || ! class_exists(DOMDocument::class)) {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $locations = [];
        foreach ($document->getElementsByTagName('loc') as $location) {
            $locations[] = trim($location->textContent);
        }

        return $locations;
    }

    /** @return array<int, string> */
    private function disallowedPaths(string $robots): array
    {
        $applies = false;
        $disallowed = [];
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(Str::before($line, '#'));
            if ($line === '' || ! Str::contains($line, ':')) {
                continue;
            }
            $field = Str::lower(trim(Str::before($line, ':')));
            $value = trim(Str::after($line, ':'));
            if ($field === 'user-agent') {
                $applies = in_array(Str::lower($value), ['*', 'peekchimpbot'], true);
            }
            if ($applies && $field === 'disallow' && $value !== '') {
                $disallowed[] = $value;
            }
        }

        return array_values(array_unique($disallowed));
    }

    /** @param array<int, string> $disallowed */
    private function isCrawlablePath(string $url, array $disallowed): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if (Str::endsWith(Str::lower($path), ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.pdf', '.zip', '.xml', '.txt'])) {
            return false;
        }

        return collect($disallowed)->doesntContain(fn (string $rule): bool => $rule === '/' || Str::startsWith($path, $rule));
    }
}
