<?php

use App\Models\AnalyticsSession;
use App\Models\Goal;
use App\Models\GoalConversion;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use App\Models\User;
use App\Services\SearchConsole\SearchConsoleAnalyticsService;

it('builds search performance comparisons and page opportunities from imported data', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $user->getKey(),
        'data_through' => '2026-08-11',
        'last_synced_at' => now(),
    ]);

    foreach ([
        ['2026-08-08', 5, 100, 10.0],
        ['2026-08-09', 5, 100, 10.0],
        ['2026-08-10', 10, 100, 5.0],
        ['2026-08-11', 20, 200, 5.0],
    ] as [$date, $clicks, $impressions, $position]) {
        SearchConsoleMetric::factory()->for($project)->create([
            'report_date' => $date,
            'dimension_type' => 'property',
            'dimension_value' => '',
            'dimension_hash' => sha1(''),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'position' => $position,
        ]);
    }

    SearchConsoleMetric::factory()->for($project)->create([
        'report_date' => '2026-08-11',
        'dimension_type' => 'page',
        'dimension_value' => 'https://example.com/guides/seo',
        'dimension_hash' => sha1('https://example.com/guides/seo'),
        'normalized_path' => '/guides/seo',
        'clicks' => 2,
        'impressions' => 200,
        'position' => 8,
    ]);
    SearchConsoleMetric::factory()->for($project)->create([
        'report_date' => '2026-08-11',
        'dimension_type' => 'query_page',
        'dimension_value' => 'simple analytics',
        'dimension_hash' => sha1("simple analytics\0https://example.com/guides/seo"),
        'normalized_path' => '/guides/seo',
        'clicks' => 2,
        'impressions' => 200,
        'position' => 8,
    ]);
    $convertedSession = AnalyticsSession::factory()->for($project)->create([
        'started_at' => '2026-08-11 10:00:00',
        'last_seen_at' => '2026-08-11 10:05:00',
        'entry_path' => '/guides/seo',
        'referrer_host' => 'www.google.com',
        'is_bounce' => false,
        'duration_seconds' => 300,
    ]);
    AnalyticsSession::factory()->for($project)->create([
        'started_at' => '2026-08-11 12:00:00',
        'last_seen_at' => '2026-08-11 12:00:10',
        'entry_path' => '/guides/seo',
        'referrer_host' => 'google.co.uk',
        'is_bounce' => true,
        'duration_seconds' => 10,
    ]);
    AnalyticsSession::factory()->for($project)->create([
        'started_at' => '2026-08-11 13:00:00',
        'entry_path' => '/guides/seo',
        'referrer_host' => 'google.com',
        'utm_medium' => 'cpc',
    ]);
    $goal = Goal::factory()->for($project)->create();
    GoalConversion::factory()->for($project)->for($goal)->create([
        'session_id' => $convertedSession->session_id,
        'occurred_at' => '2026-08-11 10:03:00',
    ]);

    $report = app(SearchConsoleAnalyticsService::class)->report($project, '2026-08-10', '2026-08-11');

    expect($report['status'])->toBe('connected')
        ->and($report['range']['from'])->toBe('2026-08-10')
        ->and($report['range']['to'])->toBe('2026-08-11')
        ->and($report['metrics']['clicks']['current'])->toBe(30)
        ->and($report['metrics']['clicks']['previous'])->toBe(10)
        ->and($report['metrics']['ctr']['current'])->toBe(10.0)
        ->and($report['pages'][0]['label'])->toBe('/guides/seo')
        ->and($report['organicFunnel']['visits'])->toBe(2)
        ->and($report['organicFunnel']['engagedVisits'])->toBe(1)
        ->and($report['organicFunnel']['conversions'])->toBe(1)
        ->and($report['landingPages'][0]['path'])->toBe('/guides/seo')
        ->and($report['landingPages'][0]['bounceRate'])->toBe(50.0)
        ->and($report['landingPages'][0]['conversionRate'])->toBe(50.0)
        ->and($report['landingPages'][0]['topQueries'][0]['query'])->toBe('simple analytics')
        ->and($report['insights'])->not->toBeEmpty()
        ->and($report['insights'][0]['recommendation'])->toBeString();
});

it('renders a deferred organic search section on the dashboard', function () {
    $dashboard = file_get_contents(resource_path('js/pages/Dashboard.vue'));

    expect($dashboard)
        ->toContain('<Deferred data="searchPerformance">')
        ->toContain('Your Google search story')
        ->toContain('Google Search Console shows how people')
        ->toContain('Three answers that matter')
        ->toContain('Are people finding the website?')
        ->toContain('What happened after they clicked?')
        ->toContain('Did the traffic create value?')
        ->toContain('What to improve next')
        ->toContain('<details class="group p-5 sm:p-6">')
        ->toContain('Explore the supporting data')
        ->toContain('Aggregate correlation, not')
        ->toContain('visitor-level attribution')
        ->not->toContain('Search-to-conversion journey');
});
