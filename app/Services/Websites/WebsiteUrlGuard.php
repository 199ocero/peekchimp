<?php

namespace App\Services\Websites;

use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebsiteUrlGuard
{
    public function allows(Project $project, string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($host)
            || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            || isset($parts['user'], $parts['pass'])
            || isset($parts['port']) && ! in_array($parts['port'], [80, 443], true)) {
            return false;
        }

        $host = Str::lower(rtrim($host, '.'));
        if (! $this->verifiedDomains($project)->contains($host)) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->addresses($host);

        return $addresses !== [] && collect($addresses)->every(
            fn (string $address): bool => filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false,
        );
    }

    /** @return Collection<int, lowercase-string> */
    public function verifiedDomains(Project $project): Collection
    {
        return $project->domains()
            ->where('is_verified', true)
            ->pluck('domain')
            ->map(fn (string $domain): string => Str::lower(rtrim($domain, '.')))
            ->values();
    }

    /** @return array<int, string> */
    protected function addresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null)
            ->filter(fn (mixed $address): bool => is_string($address))
            ->values()
            ->all();
    }
}
