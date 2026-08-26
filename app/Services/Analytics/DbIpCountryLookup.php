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
        'DBIP-Country-Lite',
        'DBIP-Country',
        'GeoLite2-Country',
        'GeoIP2-Country',
    ];

    public function find(string $ipAddress): ?string
    {
        if (! $this->isPublicIpAddress($ipAddress)) {
            return null;
        }

        $databasePath = (string) config('analytics.geolocation.database_path', '');

        if ($databasePath === '' || ! is_file($databasePath)) {
            return null;
        }

        try {
            $reader = new Reader($databasePath);
            $country = $reader->country($ipAddress)->country->isoCode;
            $reader->close();

            return is_string($country) ? strtoupper($country) : null;
        } catch (Throwable) {
            return null;
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
