<?php

use App\Services\Analytics\GeoIpDatabaseUpdater;

use function Pest\Laravel\mock;

test('the GeoIP update command installs the latest database', function () {
    $updater = mock(GeoIpDatabaseUpdater::class);
    $updater->shouldReceive('update')->once();

    $this->artisan('analytics:geoip:update')
        ->expectsOutput('GeoIP city database updated.')
        ->assertSuccessful();
});

test('the GeoIP update command reports download failures', function () {
    $updater = mock(GeoIpDatabaseUpdater::class);
    $updater->shouldReceive('update')
        ->once()
        ->andThrow(new RuntimeException('Database unavailable.'));

    $this->artisan('analytics:geoip:update')
        ->expectsOutput('Database unavailable.')
        ->assertFailed();
});
