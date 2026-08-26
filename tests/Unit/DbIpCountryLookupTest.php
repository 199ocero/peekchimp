<?php

use App\Services\Analytics\DbIpCountryLookup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

test('it degrades gracefully when the database is missing', function () {
    config()->set(
        'analytics.geolocation.database_path',
        storage_path('framework/testing/missing-country-database.mmdb'),
    );

    expect((new DbIpCountryLookup)->find('8.8.8.8'))->toBeNull();
});

test('it ignores invalid and non-public IP addresses', function (string $ipAddress) {
    expect((new DbIpCountryLookup)->find($ipAddress))->toBeNull();
})->with([
    'invalid address' => 'not-an-ip',
    'loopback address' => '127.0.0.1',
    'private address' => '10.0.0.1',
    'reserved address' => '203.0.113.10',
]);

test('it rejects corrupt database files without breaking ingestion', function () {
    $directory = storage_path('framework/testing/geoip-'.Str::random(12));
    $databasePath = $directory.'/country.mmdb';
    File::ensureDirectoryExists($directory);
    File::put($databasePath, 'not a database');
    config()->set('analytics.geolocation.database_path', $databasePath);

    try {
        expect((new DbIpCountryLookup)->find('8.8.8.8'))->toBeNull()
            ->and((new DbIpCountryLookup)->isSupportedDatabase($databasePath))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});
