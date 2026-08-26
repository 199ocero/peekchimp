<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GeoIpDatabaseUpdater
{
    public function __construct(private readonly DbIpCountryLookup $databaseLookup) {}

    public function update(): void
    {
        foreach ($this->databaseUrls() as $databaseUrl) {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->retry(
                    [250, 750],
                    when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()),
                    throw: false,
                )
                ->get($databaseUrl);

            if ($response->successful()) {
                $this->install($response->body());

                return;
            }

            if (! $response->notFound()) {
                break;
            }
        }

        throw new RuntimeException('The DB-IP country database could not be downloaded.');
    }

    /**
     * @return array<int, string>
     */
    private function databaseUrls(): array
    {
        $configuredUrl = config('analytics.geolocation.database_url');

        if (is_string($configuredUrl) && trim($configuredUrl) !== '') {
            return [trim($configuredUrl)];
        }

        $currentMonth = CarbonImmutable::now('UTC')->startOfMonth();

        return collect([$currentMonth, $currentMonth->subMonth()])
            ->map(fn (CarbonImmutable $month): string => sprintf(
                'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz',
                $month->format('Y-m'),
            ))
            ->all();
    }

    private function install(string $payload): void
    {
        $database = str_starts_with($payload, "\x1f\x8b") ? gzdecode($payload) : $payload;

        if (! is_string($database) || $database === '') {
            throw new RuntimeException('The downloaded DB-IP country database is not a valid gzip archive.');
        }

        $databasePath = (string) config('analytics.geolocation.database_path', '');

        if ($databasePath === '') {
            throw new RuntimeException('The GeoIP database path is not configured.');
        }

        File::ensureDirectoryExists(dirname($databasePath));
        $temporaryPath = $databasePath.'.'.Str::random(12).'.tmp';

        try {
            File::put($temporaryPath, $database);

            if (! $this->databaseLookup->isSupportedDatabase($temporaryPath)) {
                throw new RuntimeException('The downloaded file is not a supported country database.');
            }

            if (! File::move($temporaryPath, $databasePath)) {
                throw new RuntimeException('The GeoIP country database could not be installed.');
            }
        } finally {
            File::delete($temporaryPath);
        }
    }
}
