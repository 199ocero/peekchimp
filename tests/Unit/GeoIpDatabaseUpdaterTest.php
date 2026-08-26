<?php

use App\Services\Analytics\DbIpCountryLookup;
use App\Services\Analytics\GeoIpDatabaseUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class);

test('it falls back to the previous month and installs the database atomically', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-26 12:00:00', 'UTC'));
    $directory = storage_path('framework/testing/geoip-'.Str::random(12));
    $databasePath = $directory.'/country.mmdb';
    config()->set('analytics.geolocation.database_path', $databasePath);
    config()->set('analytics.geolocation.database_url');
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->pushStatus(404)
        ->push(gzencode('valid database'), 200);

    $databaseLookup = mock(DbIpCountryLookup::class);
    $databaseLookup->shouldReceive('isSupportedDatabase')
        ->once()
        ->withArgs(fn (string $path): bool => File::get($path) === 'valid database')
        ->andReturnTrue();

    try {
        (new GeoIpDatabaseUpdater($databaseLookup))->update();

        expect(File::get($databasePath))->toBe('valid database');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '2026-08'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '2026-07'));
    } finally {
        File::deleteDirectory($directory);
    }
});

test('it preserves the current database when an update is invalid', function () {
    $directory = storage_path('framework/testing/geoip-'.Str::random(12));
    $databasePath = $directory.'/country.mmdb';
    File::ensureDirectoryExists($directory);
    File::put($databasePath, 'current database');
    config()->set('analytics.geolocation.database_path', $databasePath);
    config()->set('analytics.geolocation.database_url', 'https://geo.example.test/country.mmdb.gz');
    Http::preventStrayRequests();
    Http::fake(['geo.example.test/*' => Http::response(gzencode('invalid database'))]);

    $databaseLookup = mock(DbIpCountryLookup::class);
    $databaseLookup->shouldReceive('isSupportedDatabase')->once()->andReturnFalse();

    try {
        expect(fn () => (new GeoIpDatabaseUpdater($databaseLookup))->update())
            ->toThrow(RuntimeException::class, 'not a supported country database')
            ->and(File::get($databasePath))->toBe('current database');
    } finally {
        File::deleteDirectory($directory);
    }
});
