<?php

use App\Jobs\RunAiVisibilityScan;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Services\Analytics\CountryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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

test('approximate city coordinates are stored only on the session', function () {
    $project = Project::factory()->create();
    $resolver = $this->mock(CountryResolver::class);
    $resolver->shouldReceive('resolveLocation')->once()->andReturn([
        'country' => 'PH',
        'latitude' => 14.5995,
        'longitude' => 120.9842,
    ]);

    $this->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    $session = AnalyticsSession::query()->sole();

    expect($session->latitude)->toBe(14.5995)
        ->and($session->longitude)->toBe(120.9842)
        ->and(AnalyticsEvent::query()->sole()->getAttributes())
        ->not->toHaveKeys(['latitude', 'longitude', 'ip_address']);
});

test('reserved browser signals are sanitized and stay out of engagement metrics', function () {
    $project = Project::factory()->create([
        'settings' => ['analytics' => ['autocapture_enabled' => true]],
    ]);
    $sessionId = 'browser-session-signals';

    $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36'])
        ->postJson(route('api.v1.events.store'), [
            'site' => $project->site_key,
            'events' => [
                [
                    'event_id' => '11111111-1111-4111-8111-111111111111',
                    'event_name' => 'page_view',
                    'session_id' => $sessionId,
                    'path' => '/checkout?token=private',
                ],
                [
                    'event_id' => '22222222-2222-4222-8222-222222222222',
                    'event_name' => 'browser_error',
                    'session_id' => $sessionId,
                    'path' => '/checkout?token=private',
                    'properties' => [
                        'error_type' => 'TypeError',
                        'script_path' => 'https://example.test/assets/app.js?secret=private',
                        'line' => 42,
                        'column' => 8,
                        'message' => 'private user value',
                        'stack' => 'private stack',
                    ],
                ],
                [
                    'event_id' => '33333333-3333-4333-8333-333333333333',
                    'event_name' => 'request_failure',
                    'session_id' => $sessionId,
                    'path' => '/checkout?token=private',
                    'properties' => [
                        'method' => 'POST',
                        'request_path' => '/api/checkout?token=private',
                        'status' => 500,
                        'duration_ms' => 812,
                        'fingerprint' => 'checkout-failure',
                        'body' => 'private request body',
                    ],
                ],
                [
                    'event_id' => '44444444-4444-4444-8444-444444444444',
                    'event_name' => 'web_vital.lcp',
                    'session_id' => $sessionId,
                    'path' => '/checkout?token=private',
                    'properties' => ['value_ms' => 2800, 'text' => 'private text'],
                ],
            ],
        ]);

    $response->assertAccepted()->assertJson(['accepted' => 4]);
    $events = AnalyticsEvent::query()->orderBy('id')->get();

    expect($events[1]->properties)->toBe([
        'error_type' => 'TypeError',
        'script_path' => '/assets/app.js',
        'line' => 42,
        'column' => 8,
    ])->and($events[2]->properties)->toBe([
        'method' => 'POST',
        'request_path' => '/api/checkout',
        'status' => 500,
        'duration_ms' => 812,
        'fingerprint' => 'checkout-failure',
    ])->and($events[3]->properties)->toBe(['value_ms' => 2800]);

    expect(AnalyticsSession::query()->sole()->custom_events)->toBe(0)
        ->and(AnalyticsSession::query()->sole()->is_bounce)->toBeTrue();
});

test('autocapture interactions count as engagement while diagnostic signals do not', function () {
    $project = Project::factory()->create([
        'settings' => ['analytics' => ['autocapture_enabled' => true]],
    ]);
    $payload = [
        'site' => $project->site_key,
        'events' => [
            [
                'event_id' => '11111111-1111-4111-8111-111111111111',
                'event_name' => 'page_view',
                'session_id' => 'browser-session-engagement',
                'path' => '/pricing',
            ],
            [
                'event_id' => '22222222-2222-4222-8222-222222222222',
                'event_name' => 'autocapture.click',
                'session_id' => 'browser-session-engagement',
                'path' => '/pricing',
                'properties' => [
                    'kind' => 'click',
                    'tag' => 'a',
                    'target' => 'try-pro',
                    'element_key' => 'pricing-pro-get-started',
                    'text' => 'Get Started',
                    'href' => '/register?token=private',
                    'id' => 'pro-cta',
                    'name' => 'register',
                    'value' => 'private input value',
                ],
            ],
        ],
    ];

    $this->postJson(route('api.v1.events.store'), $payload)->assertAccepted();

    expect(AnalyticsSession::query()->sole()->custom_events)->toBe(1)
        ->and(AnalyticsSession::query()->sole()->is_bounce)->toBeFalse()
        ->and(AnalyticsEvent::query()->where('event_name', 'autocapture.click')->sole()->properties)->toBe([
            'kind' => 'click',
            'tag' => 'a',
            'target' => 'try-pro',
            'element_key' => 'pricing-pro-get-started',
            'text' => 'Get Started',
            'href' => '/register',
            'id' => 'pro-cta',
            'name' => 'register',
        ]);
});

test('disabled browser signals are filtered at ingestion', function () {
    $project = Project::factory()->create();

    $response = $this->postJson(route('api.v1.events.store'), [
        'site' => $project->site_key,
        'events' => [
            [
                'event_id' => '11111111-1111-4111-8111-111111111111',
                'event_name' => 'page_view',
                'session_id' => 'browser-session-disabled',
                'path' => '/pricing',
            ],
            [
                'event_id' => '22222222-2222-4222-8222-222222222222',
                'event_name' => 'browser_error',
                'session_id' => 'browser-session-disabled',
                'path' => '/pricing',
                'properties' => ['error_type' => 'TypeError'],
            ],
        ],
    ]);

    $response->assertAccepted()->assertJson(['accepted' => 1, 'filtered' => 1]);
    expect(AnalyticsEvent::query()->pluck('event_name')->all())->toBe(['page_view']);
});

test('tracker config follows the project setting and origin allowlist', function () {
    $project = Project::factory()->create([
        'settings' => ['analytics' => ['autocapture_enabled' => true]],
    ]);
    $project->domains()->create(['domain' => 'example.test']);

    $this->withHeaders(['Origin' => 'https://example.test'])
        ->getJson(route('api.v1.events.config', ['site' => $project->site_key]))
        ->assertOk()
        ->assertJson(['autocapture' => true]);

    $this->withHeaders(['Origin' => 'https://not-example.test'])
        ->getJson(route('api.v1.events.config', ['site' => $project->site_key]))
        ->assertForbidden();
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
    Queue::fake();
    $project = Project::factory()->create();
    $domain = $project->domains()->create(['domain' => 'example.test']);

    $this->withHeaders([
        'Origin' => 'https://example.test',
        'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
        'Accept-Language' => 'en-US',
    ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key))
        ->assertAccepted();

    expect($domain->refresh()->is_verified)->toBeTrue();
    Queue::assertPushed(RunAiVisibilityScan::class, fn (RunAiVisibilityScan $job): bool => $job->project->is($project));
});

test('subsequent accepted pageviews do not queue another initial website crawl', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $project->domains()->create(['domain' => 'example.test']);

    foreach ([
        '11111111-1111-4111-8111-111111111111',
        '22222222-2222-4222-8222-222222222222',
    ] as $eventId) {
        $this->withHeaders([
            'Origin' => 'https://example.test',
            'User-Agent' => 'Mozilla/5.0 Chrome/126 Safari/537.36',
            'Accept-Language' => 'en-US',
        ])->postJson(route('api.v1.events.store'), analyticsPayload($project->site_key, $eventId))
            ->assertAccepted();
    }

    Queue::assertPushed(RunAiVisibilityScan::class, 1);
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
