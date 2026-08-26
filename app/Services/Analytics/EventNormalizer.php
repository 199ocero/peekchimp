<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventNormalizer
{
    public function __construct(private readonly CountryResolver $countryResolver) {}

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function normalize(array $event, Request $request, CarbonImmutable $now): array
    {
        $properties = is_array($event['properties'] ?? null) ? $event['properties'] : [];
        $platform = (string) ($event['platform'] ?? 'web');
        $path = $event['path'] ?? $properties['path'] ?? null;
        $referrer = $event['referrer'] ?? $properties['referrer'] ?? null;

        $occurredAt = isset($event['occurred_at'])
            ? CarbonImmutable::parse((string) $event['occurred_at'])
            : $now;

        if ($occurredAt->lt($now->subDay())) {
            $occurredAt = $now->subDay();
        }

        if ($occurredAt->gt($now->addMinutes(5))) {
            $occurredAt = $now;
        }

        $customProperties = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key) || in_array($key, ['path', 'referrer'], true)) {
                continue;
            }

            if (is_string($value)) {
                $customProperties[Str::limit($key, 64, '')] = Str::limit($value, 256, '');
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $customProperties[Str::limit($key, 64, '')] = $value;
            }

            if (count($customProperties) >= 20) {
                break;
            }
        }

        return [
            'event_id' => (string) ($event['event_id'] ?? Str::uuid()),
            'event_name' => (string) $event['event_name'],
            'platform' => $platform,
            'client_session_id' => isset($event['session_id']) ? (string) $event['session_id'] : null,
            'path' => $this->path($path),
            'referrer_host' => $this->host($referrer),
            'country' => $this->countryResolver->resolve($request),
            'device' => $this->device((string) $request->userAgent()),
            'browser' => $this->browser((string) $request->userAgent()),
            'operating_system' => $this->operatingSystem((string) $request->userAgent()),
            'utm_source' => $this->stringValue($event['utm_source'] ?? $properties['utm_source'] ?? null, 120),
            'utm_medium' => $this->stringValue($event['utm_medium'] ?? $properties['utm_medium'] ?? null, 120),
            'utm_campaign' => $this->stringValue($event['utm_campaign'] ?? $properties['utm_campaign'] ?? null, 160),
            'properties' => $customProperties,
            'occurred_at' => $occurredAt,
        ];
    }

    private function path(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $parsed = parse_url($value, PHP_URL_PATH);
        $path = is_string($parsed) && $parsed !== '' ? $parsed : $value;

        return '/'.ltrim(Str::limit($path, 2047, ''), '/');
    }

    private function host(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? Str::lower(Str::limit($host, 255, '')) : null;
    }

    private function stringValue(mixed $value, int $length): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $length, '') : null;
    }

    private function device(string $userAgent): string
    {
        $userAgent = strtolower($userAgent);

        return str_contains($userAgent, 'tablet') || str_contains($userAgent, 'ipad')
            ? 'tablet'
            : (preg_match('/mobile|iphone|android/', $userAgent) === 1 ? 'mobile' : 'desktop');
    }

    private function browser(string $userAgent): string
    {
        $userAgent = Str::lower($userAgent);

        return match (true) {
            str_contains($userAgent, 'edg') => 'Edge',
            str_contains($userAgent, 'opr') || str_contains($userAgent, 'opera') || str_contains($userAgent, 'opios') => 'Opera',
            str_contains($userAgent, 'chrome') || str_contains($userAgent, 'crios') || str_contains($userAgent, 'chromium') => 'Chrome',
            str_contains($userAgent, 'firefox') || str_contains($userAgent, 'fxios') => 'Firefox',
            str_contains($userAgent, 'safari') => 'Safari',
            default => 'Other',
        };
    }

    private function operatingSystem(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'windows') => 'Windows',
            str_contains($userAgent, 'iphone') || str_contains($userAgent, 'ipad') => 'iOS',
            str_contains($userAgent, 'android') => 'Android',
            str_contains($userAgent, 'mac os') || str_contains($userAgent, 'macintosh') => 'macOS',
            str_contains($userAgent, 'linux') => 'Linux',
            default => 'Other',
        };
    }
}
