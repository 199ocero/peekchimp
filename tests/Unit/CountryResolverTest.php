<?php

use App\Services\Analytics\CountryResolver;
use App\Services\Analytics\DbIpCountryLookup;
use Illuminate\Http\Request;
use Tests\TestCase;

use function Pest\Laravel\mock;

uses(TestCase::class);

test('it resolves countries from supported hosting headers', function (string $header) {
    config()->set('analytics.geolocation.country_headers', [
        'CF-IPCountry',
        'X-Vercel-IP-Country',
        'CloudFront-Viewer-Country',
        'Eo-IpCountry',
    ]);

    $databaseLookup = mock(DbIpCountryLookup::class);
    $databaseLookup->shouldReceive('findLocation')->once()->andReturn([
        'country' => 'US',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);
    $request = Request::create('/', 'POST');
    $request->headers->set($header, 'ph');

    expect((new CountryResolver($databaseLookup))->resolveLocation($request))->toBe([
        'country' => 'PH',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);
})->with([
    'Cloudflare' => 'CF-IPCountry',
    'Vercel' => 'X-Vercel-IP-Country',
    'CloudFront' => 'CloudFront-Viewer-Country',
    'EdgeOne' => 'Eo-IpCountry',
]);

test('it falls back to the local database when headers are unavailable or invalid', function () {
    config()->set('analytics.geolocation.country_headers', ['CF-IPCountry']);

    $databaseLookup = mock(DbIpCountryLookup::class);
    $databaseLookup->shouldReceive('findLocation')->once()->with('8.8.8.8')->andReturn([
        'country' => 'us',
        'latitude' => 37.751,
        'longitude' => -97.822,
    ]);
    $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '8.8.8.8']);
    $request->headers->set('CF-IPCountry', 'XX');

    expect((new CountryResolver($databaseLookup))->resolve($request))->toBe('US');
});

test('it leaves visits unknown when neither source resolves a country', function () {
    config()->set('analytics.geolocation.country_headers', ['CF-IPCountry']);

    $databaseLookup = mock(DbIpCountryLookup::class);
    $databaseLookup->shouldReceive('findLocation')->once()->andReturn([
        'country' => null,
        'latitude' => null,
        'longitude' => null,
    ]);
    $request = Request::create('/', 'POST');

    expect((new CountryResolver($databaseLookup))->resolve($request))->toBeNull();
});
