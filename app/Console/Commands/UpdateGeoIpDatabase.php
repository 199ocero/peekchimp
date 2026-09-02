<?php

namespace App\Console\Commands;

use App\Services\Analytics\GeoIpDatabaseUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('analytics:geoip:update')]
#[Description('Download the latest city database used for analytics geolocation')]
class UpdateGeoIpDatabase extends Command
{
    public function handle(GeoIpDatabaseUpdater $updater): int
    {
        try {
            $updater->update();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('GeoIP city database updated.');

        return self::SUCCESS;
    }
}
