<?php

namespace App\Services\Analytics;

use GeoIp2\Database\Reader;
use Throwable;

class DbIpCountryLookup
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_DATABASE_TYPES = [
        'DBIP-City-Lite',
        'DBIP-City',
        'GeoLite2-City',
        'GeoIP2-City',
    ];

    public function find(string $ipAddress): ?string
    {
        return $this->findLocation($ipAddress)['country'];
    }

    /** @return array{country: string|null, latitude: float|null, longitude: float|null} */
    public function findLocation(string $ipAddress): array
    {
        $empty = ['country' => null, 'latitude' => null, 'longitude' => null];

        if (! $this->isPublicIpAddress($ipAddress)) {
            return $empty;
        }

        $databasePath = (string) config('analytics.geolocation.database_path', '');

        if ($databasePath === '' || ! is_file($databasePath)) {
            return $empty;
        }

        $reader = null;

        try {
            $reader = new Reader($databasePath);
            $isCityDatabase = str_contains($reader->metadata()->databaseType, 'City');
            $latitude = null;
            $longitude = null;

            if ($isCityDatabase) {
                $record = $reader->city($ipAddress);
                $country = $record->country->isoCode;
                $latitude = is_numeric($record->location->latitude)
                    ? (float) $record->location->latitude
                    : null;
                $longitude = is_numeric($record->location->longitude)
                    ? (float) $record->location->longitude
                    : null;
            } else {
                $country = $reader->country($ipAddress)->country->isoCode;
            }

            return [
                'country' => is_string($country) ? strtoupper($country) : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        } catch (Throwable) {
            return $empty;
        } finally {
            $reader?->close();
        }
    }

    public function isSupportedDatabase(string $databasePath): bool
    {
        try {
            $reader = new Reader($databasePath);
            $isSupported = in_array(
                $reader->metadata()->databaseType,
                self::SUPPORTED_DATABASE_TYPES,
                true,
            );
            $reader->close();

            return $isSupported;
        } catch (Throwable) {
            return false;
        }
    }

    private function isPublicIpAddress(string $ipAddress): bool
    {
        return filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
