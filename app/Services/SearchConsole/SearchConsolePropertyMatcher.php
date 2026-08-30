<?php

namespace App\Services\SearchConsole;

use App\Models\Project;

class SearchConsolePropertyMatcher
{
    /**
     * @param  array<int, array{siteUrl: string, permissionLevel: string}>  $sites
     * @return array<int, array{siteUrl: string, permissionLevel: string, propertyType: string, host: string}>
     */
    public function matching(Project $project, array $sites): array
    {
        $verifiedDomains = $project->domains()
            ->where('is_verified', true)
            ->pluck('domain')
            ->map(fn (string $domain): string => $this->normalizeHost($domain))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return collect($sites)
            ->reject(fn (array $site): bool => $site['permissionLevel'] === 'siteUnverifiedUser')
            ->map(function (array $site): ?array {
                $host = $this->host($site['siteUrl']);

                if ($host === null) {
                    return null;
                }

                return [
                    ...$site,
                    'propertyType' => str_starts_with($site['siteUrl'], 'sc-domain:') ? 'domain' : 'url_prefix',
                    'host' => $host,
                ];
            })
            ->filter(fn (?array $site): bool => $site !== null && in_array($site['host'], $verifiedDomains, true))
            ->values()
            ->all();
    }

    public function host(string $siteUrl): ?string
    {
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            $host = substr($siteUrl, strlen('sc-domain:'));

            return $this->normalizeHost($host);
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);

        return is_string($host) ? $this->normalizeHost($host) : null;
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
