<?php

namespace App\Services\Analytics;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AiVisibilityScanner
{
    /**
     * Scan only a verified project domain and retain findings, never page bodies.
     */
    public function scan(Project $project, AiVisibilityScan $scan): void
    {
        $domain = $project->domains()->where('is_verified', true)->first()?->domain;

        if ($domain === null) {
            $this->failed($scan, 'A verified domain is required.');

            return;
        }

        $baseUrl = 'https://'.$domain;
        if (! $this->isSafeUrl($baseUrl, $project)) {
            $this->failed($scan, 'The verified domain could not be safely reached.');

            return;
        }

        $scan->forceFill(['status' => 'running', 'started_at' => now(), 'error' => null])->save();
        $client = Http::accept('text/html, text/plain')->timeout(5)->connectTimeout(3)->withoutRedirecting();
        $findings = [];
        $score = 0;
        $robots = $this->get($client, $baseUrl.'/robots.txt', $project);
        $findings['robots'] = ['available' => $robots?->successful() === true, 'status' => $robots?->status()];
        $score += $robots?->successful() === true ? 1 : 0;
        $sitemap = $this->get($client, $baseUrl.'/sitemap.xml', $project);
        $findings['sitemap'] = ['available' => $sitemap?->successful() === true, 'status' => $sitemap?->status()];
        $score += $sitemap?->successful() === true ? 1 : 0;
        $home = $this->get($client, $baseUrl.'/', $project);

        if ($home === null || ! $home->successful()) {
            $this->failed($scan, 'The homepage could not be reached.', $findings);

            return;
        }

        $html = (string) $home->body();
        $title = preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $titleMatch) === 1 && trim(strip_tags($titleMatch[1])) !== '';
        $description = preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $html) === 1;
        $schema = preg_match('/application\/ld\+json/i', $html) === 1;
        $findings['homepage'] = ['available' => true, 'status' => $home->status(), 'title' => $title, 'description' => $description, 'structuredData' => $schema];
        $score += (int) $title + (int) $description + (int) $schema + 1;
        $llms = $this->get($client, $baseUrl.'/llms.txt', $project);
        $findings['llmsTxt'] = ['available' => $llms?->successful() === true, 'status' => $llms?->status()];
        $score += $llms?->successful() === true ? 1 : 0;
        $findings['pages'] = $this->linkedPageChecks($client, $baseUrl, $html, $project);
        $score += count(array_filter($findings['pages'], static fn (array $page): bool => $page['title'] && $page['description']));

        $scan->forceFill([
            'status' => 'completed',
            'score' => (int) round((min(7, $score) / 7) * 100),
            'findings' => $findings,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * @return array<int, array{path: string, title: bool, description: bool, structuredData: bool, status: int|null}>
     */
    private function linkedPageChecks(PendingRequest $client, string $baseUrl, string $html, Project $project): array
    {
        if (! class_exists(\DOMDocument::class)) {
            return [];
        }

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $paths = [];

        foreach ($document->getElementsByTagName('a') as $link) {
            $href = trim((string) $link->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                continue;
            }
            $url = filter_var($href, FILTER_VALIDATE_URL) ? $href : rtrim($baseUrl, '/').'/'.ltrim($href, '/');
            if (parse_url($url, PHP_URL_HOST) !== $baseHost || ! $this->isSafeUrl($url, $project)) {
                continue;
            }
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            if (isset($paths[$path])) {
                continue;
            }
            $paths[$path] = true;
            if (count($paths) >= 5) {
                break;
            }
        }

        $checks = [];
        foreach (array_keys($paths) as $path) {
            $response = $this->get($client, rtrim($baseUrl, '/').$path, $project);
            $pageHtml = $response?->successful() ? (string) $response->body() : '';
            $checks[] = [
                'path' => $path,
                'title' => preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $pageHtml, $match) === 1 && trim(strip_tags($match[1])) !== '',
                'description' => preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $pageHtml) === 1,
                'structuredData' => preg_match('/application\/ld\+json/i', $pageHtml) === 1,
                'status' => $response?->status(),
            ];
        }

        return $checks;
    }

    private function get(PendingRequest $client, string $url, Project $project): ?Response
    {
        if (! $this->isSafeUrl($url, $project)) {
            return null;
        }

        try {
            return $client->get($url);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSafeUrl(string $url, Project $project): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! is_string($host) || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || isset($parts['user'], $parts['pass']) || isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            return false;
        }
        $host = strtolower(rtrim($host, '.'));
        $allowed = $project->domains()->where('is_verified', true)->pluck('domain')->map(fn (string $domain): string => strtolower(rtrim($domain, '.')));
        if (! $allowed->contains($host)) {
            return false;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $findings */
    private function failed(AiVisibilityScan $scan, string $error, array $findings = []): void
    {
        $scan->forceFill(['status' => 'failed', 'error' => $error, 'findings' => $findings, 'completed_at' => now()])->save();
    }
}
