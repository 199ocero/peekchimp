<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function analyticsPayload(
    string $siteKey,
    string $eventId = '11111111-1111-4111-8111-111111111111',
    string $eventName = 'page_view',
): array {
    return [
        'site' => $siteKey,
        'events' => [[
            'event_id' => $eventId,
            'event_name' => $eventName,
            'platform' => 'web',
            'session_id' => 'browser-session-1',
            'path' => '/pricing?utm_source=newsletter',
            'referrer' => 'https://search.example.test/results?q=peekchimp',
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'launch',
            'properties' => ['plan' => 'pro', 'ignored' => ['nested' => true]],
        ]],
    ];
}

test('a browser event is normalized and stored without raw identity data', function () {
    $project = Project::factory()->create();

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US,en;q=0.9',
        'CF-IPCountry' => 'PH',
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key));

    $response->assertAccepted()
        ->assertJson(['accepted' => 1, 'filtered' => 0, 'duplicate' => 0])
        ->assertJsonMissingPath('accepted_page_view');
    $event = AnalyticsEvent::query()->firstOrFail();

    expect($event->path)->toBe('/pricing')
        ->and($event->referrer_host)->toBe('search.example.test')
        ->and($event->country)->toBe('PH')
        ->and($event->properties)->toBe(['plan' => 'pro'])
        ->and(strlen($event->visitor_id))->toBe(64)
        ->and(strlen($event->session_id))->toBe(64);

    expect($event->getAttributes())->not->toHaveKeys(['ip_address', 'user_agent']);
    expect(AnalyticsSession::query()->count())->toBe(1)
        ->and(AnalyticsSession::query()->sole()->country)->toBe('PH');
});

test('browser user agents are classified', function (string $userAgent, string $browser) {
    $project = Project::factory()->create();

    $this->withHeaders(['User-Agent' => $userAgent])
        ->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    expect(AnalyticsEvent::query()->sole()->browser)->toBe($browser)
        ->and(AnalyticsSession::query()->sole()->browser)->toBe($browser);
})->with([
    'Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36',
        'Chrome',
    ],
    'Chrome on iOS' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 CriOS/139.0 Mobile/15E148 Safari/604.1',
        'Chrome',
    ],
    'Edge' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36 Edg/139.0.0.0',
        'Edge',
    ],
    'Opera' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36 OPR/120.0.0.0',
        'Opera',
    ],
    'Firefox' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:142.0) Gecko/20100101 Firefox/142.0',
        'Firefox',
    ],
    'Safari' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.6 Safari/605.1.15',
        'Safari',
    ],
]);

test('a later classified event repairs the browser on its existing session', function () {
    $project = Project::factory()->create();
    $chromeUserAgent = 'Mozilla/5.0 AppleWebKit/537.36 Chrome/139.0.0.0 Safari/537.36';

    $this->withHeaders(['User-Agent' => $chromeUserAgent])
        ->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();
    AnalyticsSession::query()->sole()->update(['browser' => 'Other']);

    $this->withHeaders(['User-Agent' => $chromeUserAgent])->postJson(
        route('api.v1.events.store'),
        analyticsPayload($project->site_key, '22222222-2222-4222-8222-222222222222'),
    )->assertAccepted();

    expect(AnalyticsSession::query()->sole()->browser)->toBe('Chrome');
});

test('an invalid country header is not stored', function (string $country) {
    $project = Project::factory()->create();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
        'CF-IPCountry' => $country,
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    expect(AnalyticsEvent::query()->sole()->country)->toBeNull()
        ->and(AnalyticsSession::query()->sole()->country)->toBeNull();
})->with([
    'unknown country' => 'XX',
    'three-letter code' => 'USA',
    'non-letter code' => '1A',
]);

test('a supported hosting country header is stored without Cloudflare', function () {
    $project = Project::factory()->create();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'X-Vercel-IP-Country' => 'SG',
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    expect(AnalyticsEvent::query()->sole()->country)->toBe('SG')
        ->and(AnalyticsSession::query()->sole()->country)->toBe('SG');
});

test('a later classified event fills the country on its existing session', function () {
    $project = Project::factory()->create();

    $request = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
    ]);
    $request->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'CF-IPCountry' => 'PH',
    ])->postJson(
        route('api.v1.events.store'),
        analyticsPayload($project->site_key, '22222222-2222-4222-8222-222222222222'),
    )->assertAccepted();

    expect(AnalyticsSession::query()->sole()->country)->toBe('PH');
});

test('the same event id is idempotent', function () {
    $project = Project::factory()->create();
    $request = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ]);

    $request->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key));
    $response = $request->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key));

    $response->assertAccepted()->assertJson(['accepted' => 0, 'duplicate' => 1]);
    expect(AnalyticsEvent::query()->count())->toBe(1);
});

test('crawler traffic is filtered before persistence', function () {
    $project = Project::factory()->create();

    $response = $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->postJson(
        route('api.v1.events.store'),
        analyticsPayload($project->site_key),
    );

    $response->assertAccepted()->assertJson(['accepted' => 0, 'filtered' => 1]);
    expect(AnalyticsEvent::query()->count())->toBe(0);
});

test('registered domains protect a project event endpoint', function () {
    $project = Project::factory()->create();
    $project->domains()->create(['domain' => 'example.test']);

    $response = $this->withHeaders([
        'Origin' => 'https://not-example.test',
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key));

    $response->assertForbidden();
    expect($project->domains()->sole()->is_verified)->toBeFalse();
});

test('an accepted pageview from the registered domain verifies it', function () {
    $project = Project::factory()->create();
    $domain = $project->domains()->create(['domain' => 'example.test']);

    $this->withHeaders([
        'Origin' => 'https://example.test',
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    expect($domain->refresh()->is_verified)->toBeTrue();
});

test('a pageview without an origin does not verify a registered domain', function () {
    $project = Project::factory()->create();
    $domain = $project->domains()->create(['domain' => 'example.test']);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    expect($domain->refresh()->is_verified)->toBeFalse();
});

test('a custom event does not verify a registered domain', function () {
    $project = Project::factory()->create();
    $domain = $project->domains()->create(['domain' => 'example.test']);

    $this->withHeaders([
        'Origin' => 'https://example.test',
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(
        route('api.v1.events.store'),
        analyticsPayload($project->site_key, eventName: 'signup'),
    )->assertAccepted();

    expect($domain->refresh()->is_verified)->toBeFalse();
});

test('a duplicate pageview does not verify a registered domain', function () {
    $project = Project::factory()->create();
    $domain = $project->domains()->create(['domain' => 'example.test']);
    $payload = analyticsPayload($project->site_key);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(route('api.v1.events.store'), $payload)->assertAccepted();

    $this->withHeaders([
        'Origin' => 'https://example.test',
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(route('api.v1.events.store'), $payload)
        ->assertAccepted()
        ->assertJson(['accepted' => 0, 'duplicate' => 1]);

    expect($domain->refresh()->is_verified)->toBeFalse();
});
