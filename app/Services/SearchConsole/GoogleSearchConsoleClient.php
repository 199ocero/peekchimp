<?php

namespace App\Services\SearchConsole;

use App\Contracts\SearchConsoleClient;
use App\Exceptions\SearchConsoleReconnectRequiredException;
use App\Models\SearchConsoleConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class GoogleSearchConsoleClient implements SearchConsoleClient
{
    public function authorizationUrl(string $state): string
    {
        $this->ensureConfigured();

        return (string) config('services.google_search_console.authorization_url').'?'.http_build_query([
            'client_id' => config('services.google_search_console.client_id'),
            'redirect_uri' => config('services.google_search_console.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('services.google_search_console.scope'),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $this->ensureConfigured();
        $response = $this->request()->asForm()->post((string) config('services.google_search_console.token_url'), [
            'client_id' => config('services.google_search_console.client_id'),
            'client_secret' => config('services.google_search_console.client_secret'),
            'redirect_uri' => config('services.google_search_console.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ])->throw();

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');

        if (! is_string($accessToken) || ! is_string($refreshToken)) {
            throw new RuntimeException('Google did not return the required offline access credentials.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    public function listSites(string $accessToken): array
    {
        $response = $this->request()
            ->withToken($accessToken)
            ->get(rtrim((string) config('services.google_search_console.api_url'), '/').'/sites')
            ->throw();

        $entries = $response->json('siteEntry', []);

        if (! is_array($entries)) {
            return [];
        }

        $sites = [];

        foreach ($entries as $site) {
            if (! is_array($site)
                || ! is_string($site['siteUrl'] ?? null)
                || ! is_string($site['permissionLevel'] ?? null)) {
                continue;
            }

            $sites[] = [
                'siteUrl' => $site['siteUrl'],
                'permissionLevel' => $site['permissionLevel'],
            ];
        }

        return $sites;
    }

    public function latestDataDate(SearchConsoleConnection $connection): ?CarbonImmutable
    {
        $end = CarbonImmutable::today('UTC');
        $rows = $this->searchAnalytics($connection, [
            'startDate' => $end->subDays(10)->toDateString(),
            'endDate' => $end->toDateString(),
            'dimensions' => ['date'],
            'type' => 'web',
            'dataState' => 'final',
            'rowLimit' => 10,
        ]);

        $dates = collect($rows)->pluck('keys.0')->filter(fn (mixed $date): bool => is_string($date))->sort();
        $latest = $dates->last();

        return is_string($latest) ? CarbonImmutable::parse($latest, 'UTC')->startOfDay() : null;
    }

    /**
     * @param  array<int, string>  $dimensions
     * @return array<int, array{keys?: array<int, string>, clicks: float|int, impressions: float|int, ctr?: float|int, position?: float|int}>
     */
    public function query(SearchConsoleConnection $connection, CarbonImmutable $date, array $dimensions = []): array
    {
        $payload = [
            'startDate' => $date->toDateString(),
            'endDate' => $date->toDateString(),
            'type' => 'web',
            'dataState' => 'final',
            'rowLimit' => $dimensions === [] ? 1 : (int) config('analytics.search_console.detail_row_limit', 1000),
        ];

        if ($dimensions !== []) {
            $payload['dimensions'] = $dimensions;
        }

        return $this->searchAnalytics($connection, $payload);
    }

    public function revoke(SearchConsoleConnection $connection): void
    {
        $token = $connection->refresh_token ?: $connection->access_token;

        if (! is_string($token) || $token === '') {
            return;
        }

        $this->request()->asForm()->post((string) config('services.google_search_console.revoke_url'), [
            'token' => $token,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{keys?: array<int, string>, clicks: float|int, impressions: float|int, ctr?: float|int, position?: float|int}>
     */
    private function searchAnalytics(SearchConsoleConnection $connection, array $payload): array
    {
        $accessToken = $this->accessToken($connection);
        $siteUrl = rawurlencode($connection->property_site_url);
        $response = $this->request()
            ->withToken($accessToken)
            ->post(rtrim((string) config('services.google_search_console.api_url'), '/').'/sites/'.$siteUrl.'/searchAnalytics/query', $payload);

        if ($response->status() === 401) {
            throw new SearchConsoleReconnectRequiredException('Google Search Console authorization has expired.');
        }

        $response->throw();

        $responseRows = $response->json('rows', []);

        if (! is_array($responseRows)) {
            return [];
        }

        $rows = [];

        foreach ($responseRows as $row) {
            if (! is_array($row)
                || ! is_numeric($row['clicks'] ?? null)
                || ! is_numeric($row['impressions'] ?? null)) {
                continue;
            }

            $normalized = [
                'clicks' => (float) $row['clicks'],
                'impressions' => (float) $row['impressions'],
            ];
            $keys = $row['keys'] ?? null;

            if (is_array($keys) && array_is_list($keys) && count(array_filter($keys, 'is_string')) === count($keys)) {
                $normalized['keys'] = $keys;
            }

            foreach (['ctr', 'position'] as $metric) {
                if (is_numeric($row[$metric] ?? null)) {
                    $normalized[$metric] = (float) $row[$metric];
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    private function accessToken(SearchConsoleConnection $connection): string
    {
        if (is_string($connection->access_token)
            && $connection->access_token !== ''
            && $connection->access_token_expires_at?->isAfter(now()->addMinute())) {
            return $connection->access_token;
        }

        return Cache::lock('search-console:token:'.$connection->getKey(), 15)->block(5, function () use ($connection): string {
            $connection->refresh();

            if (is_string($connection->access_token)
                && $connection->access_token !== ''
                && $connection->access_token_expires_at?->isAfter(now()->addMinute())) {
                return $connection->access_token;
            }

            $response = $this->request()->asForm()->post((string) config('services.google_search_console.token_url'), [
                'client_id' => config('services.google_search_console.client_id'),
                'client_secret' => config('services.google_search_console.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);

            if ($response->status() === 400 && $response->json('error') === 'invalid_grant') {
                throw new SearchConsoleReconnectRequiredException('Google Search Console authorization has expired.');
            }

            $response->throw();
            $accessToken = $response->json('access_token');

            if (! is_string($accessToken)) {
                throw new RuntimeException('Google did not return an access token.');
            }

            $connection->update([
                'access_token' => $accessToken,
                'access_token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            ]);

            return $accessToken;
        });
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout((int) config('analytics.search_console.connect_timeout_seconds', 5))
            ->timeout((int) config('analytics.search_console.request_timeout_seconds', 20))
            ->retry(
                [200, 500, 1000],
                0,
                static fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->serverError() || $exception->response->status() === 429)),
                throw: false,
            );
    }

    private function ensureConfigured(): void
    {
        if (blank(config('services.google_search_console.client_id'))
            || blank(config('services.google_search_console.client_secret'))
            || blank(config('services.google_search_console.redirect_uri'))) {
            throw new RuntimeException('Google Search Console OAuth is not configured.');
        }
    }
}
