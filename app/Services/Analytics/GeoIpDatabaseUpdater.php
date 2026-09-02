<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
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
        $databasePath = $this->databasePath();
        File::ensureDirectoryExists(dirname($databasePath));
        $downloadPath = $databasePath.'.'.Str::random(12).'.download';

        try {
            foreach ($this->databaseUrls() as $databaseUrl) {
                $response = Http::connectTimeout(5)
                    ->timeout(1800)
                    ->retry(
                        [250, 750],
                        when: static fn (Throwable $exception): bool => $exception instanceof RequestException
                            && $exception->response->serverError(),
                        throw: false,
                    )
                    ->sink($downloadPath)
                    ->get($databaseUrl);

                if ($response->successful()) {
                    $this->install($downloadPath, $databasePath);

                    return;
                }

                File::delete($downloadPath);

                if (! $response->notFound()) {
                    break;
                }
            }
        } finally {
            File::delete($downloadPath);
        }

        throw new RuntimeException('The DB-IP city database could not be downloaded.');
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
                'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz',
                $month->format('Y-m'),
            ))
            ->all();
    }

    private function install(string $downloadPath, string $databasePath): void
    {
        $temporaryPath = $databasePath.'.'.Str::random(12).'.tmp';
        $header = file_get_contents($downloadPath, false, null, 0, 2);
        $source = $header === "\x1f\x8b"
            ? gzopen($downloadPath, 'rb')
            : fopen($downloadPath, 'rb');
        $destination = fopen($temporaryPath, 'wb');

        try {
            if ($source === false || $destination === false || stream_copy_to_stream($source, $destination) <= 0) {
                throw new RuntimeException('The downloaded DB-IP city database is not a valid archive.');
            }

            fclose($source);
            fflush($destination);
            fclose($destination);
            $source = null;
            $destination = null;

            if (! $this->databaseLookup->isSupportedDatabase($temporaryPath)) {
                throw new RuntimeException('The downloaded file is not a supported city database.');
            }

            if (! File::move($temporaryPath, $databasePath)) {
                throw new RuntimeException('The GeoIP city database could not be installed.');
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($destination)) {
                fclose($destination);
            }

            File::delete($temporaryPath);
        }
    }

    private function databasePath(): string
    {
        $databasePath = (string) config('analytics.geolocation.database_path', '');

        if ($databasePath === '') {
            throw new RuntimeException('The GeoIP database path is not configured.');
        }

        return $databasePath;
    }
}
