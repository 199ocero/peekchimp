<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

class AiReferralClassifier
{
    /**
     * @var array<string, array{label: string, hosts: array<int, string>, utm_sources: array<int, string>}>
     */
    private readonly array $providers;

    /**
     * @param  array<string, array{label: string, hosts: array<int, string>, utm_sources: array<int, string>}>  $providers
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers ?: (array) config('analytics.ai_referrals', []);
    }

    public function classify(?string $referrerHost, ?string $utmSource): ?string
    {
        $normalizedUtmSource = $this->normalize($utmSource);

        foreach ($this->providers as $key => $provider) {
            if (in_array($normalizedUtmSource, $this->normalizedValues($provider['utm_sources']), true)) {
                return (string) $key;
            }
        }

        $normalizedHost = $this->normalizeHost($referrerHost);

        foreach ($this->providers as $key => $provider) {
            if ($this->matchesHost($normalizedHost, $provider['hosts'])) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function referrerHosts(): array
    {
        return collect($this->providers)
            ->flatMap(fn (array $provider): array => $provider['hosts'])
            ->map(fn (string $host): string => $this->normalizeHost($host))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function utmSources(): array
    {
        return collect($this->providers)
            ->flatMap(fn (array $provider): array => $provider['utm_sources'])
            ->map(fn (string $source): string => $this->normalize($source))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function label(string $key): string
    {
        return (string) ($this->providers[$key]['label'] ?? $key);
    }

    /**
     * @param  array<int, string>  $hosts
     */
    private function matchesHost(string $host, array $hosts): bool
    {
        foreach ($hosts as $candidate) {
            $candidate = $this->normalizeHost($candidate);

            if ($host === $candidate || Str::endsWith($host, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHost(?string $host): string
    {
        return Str::of($host ?? '')->trim()->lower()->rtrim('.')->toString();
    }

    private function normalize(?string $value): string
    {
        return Str::of($value ?? '')->trim()->lower()->toString();
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function normalizedValues(array $values): array
    {
        return array_map(fn (string $value): string => $this->normalize($value), $values);
    }
}
