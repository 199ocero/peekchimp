<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard reports persisted analytics metrics', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My first site']);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);
    AnalyticsEvent::factory()->count(3)->create([
        'project_id' => $project->id,
        'event_name' => 'page_view',
    ]);
    AnalyticsSession::factory()->count(2)->create([
        'project_id' => $project->id,
        'is_bounce' => false,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('project.name', 'My first site')
        ->where('analytics.metrics.pageviews', 3)
        ->where('analytics.metrics.sessions', 2)
        ->where('analytics.metrics.activeVisitors', 2)
        ->where('analytics.metrics.bounceRate', 0)
        ->missing('analytics.insights')
        ->missing('analytics.actionableInsights')
        ->missing('analytics.whatChanged'));
});

test('dashboard groups every selected-range visit by country', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-24 12:00:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'UTC',
    ]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);

    foreach (['US', 'US', 'US', 'PH', 'PH', 'CA', 'JP', 'DE', 'BR', 'AU', 'GB', 'FR', 'IN'] as $country) {
        AnalyticsSession::factory()->create([
            'project_id' => $project->id,
            'country' => $country,
            'pageviews' => $country === 'US' ? 20 : 1,
            'started_at' => now()->subDay(),
        ]);
    }

    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'country' => null,
        'started_at' => now()->subDay(),
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'country' => 'ES',
        'started_at' => now()->subDays(8),
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => Project::factory(),
        'country' => 'MX',
        'started_at' => now()->subDay(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['range' => '7d']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.countryVisits.total', 14)
        ->where('analytics.countryVisits.unknown', 1)
        ->has('analytics.countryVisits.countries', 10)
        ->where('analytics.countryVisits.countries.0', ['code' => 'US', 'visits' => 3])
        ->where('analytics.countryVisits.countries.1', ['code' => 'PH', 'visits' => 2]));
});

test('dashboard counts distinct visitors active in the last five minutes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-23 12:00:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);

    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'active-visitor',
        'last_seen_at' => now()->subMinute(),
        'is_bounce' => true,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'active-visitor',
        'last_seen_at' => now()->subMinutes(2),
        'is_bounce' => true,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'boundary-visitor',
        'last_seen_at' => now()->subMinutes(5),
        'is_bounce' => false,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'inactive-visitor',
        'last_seen_at' => now()->subMinutes(5)->subSecond(),
        'is_bounce' => false,
    ]);

    $otherProject = Project::factory()->create();
    AnalyticsSession::factory()->create([
        'project_id' => $otherProject->id,
        'last_seen_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.metrics.activeVisitors', 2)
        ->where('analytics.metrics.bounceRate', 50));
});

test('visitor map shows located visitors from the current project day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-23 12:00:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'Asia/Manila',
    ]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);

    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'active-located',
        'last_seen_at' => now()->subMinutes(2),
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'country' => 'PH',
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'recent-located',
        'last_seen_at' => now()->subMinutes(6),
        'latitude' => 35.6762,
        'longitude' => 139.6503,
        'country' => 'JP',
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'unlocated',
        'last_seen_at' => now()->subHour(),
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'before-local-midnight',
        'last_seen_at' => CarbonImmutable::parse('2026-08-22 15:59:59', 'UTC'),
        'latitude' => 40.7128,
        'longitude' => -74.006,
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('visitorMap.totalVisitors', 3)
        ->where('visitorMap.locatedVisitors', 2)
        ->has('visitorMap.visitors', 2)
        ->where('visitorMap.visitors.0.country', 'PH')
        ->where('visitorMap.visitors.0.active', true)
        ->where('visitorMap.visitors.1.country', 'JP')
        ->where('visitorMap.visitors.1.active', false));
});

test('dashboard compares each top metric with its previous period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-23 12:00:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'UTC',
    ]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);

    foreach ([
        ['current-a', '2026-08-22 08:00:00'],
        ['current-a', '2026-08-22 09:00:00'],
        ['current-b', '2026-08-22 09:30:00'],
    ] as [$visitorId, $occurredAt]) {
        AnalyticsEvent::factory()->create([
            'project_id' => $project->id,
            'visitor_id' => $visitorId,
            'event_name' => 'page_view',
            'occurred_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
        ]);
    }

    foreach (['2026-08-21 08:00:00', '2026-08-21 09:00:00'] as $occurredAt) {
        AnalyticsEvent::factory()->create([
            'project_id' => $project->id,
            'visitor_id' => 'previous-a',
            'event_name' => 'page_view',
            'occurred_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
        ]);
    }

    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'started_at' => CarbonImmutable::parse('2026-08-22 08:00:00', 'UTC'),
        'last_seen_at' => CarbonImmutable::parse('2026-08-22 08:01:00', 'UTC'),
        'duration_seconds' => 60,
        'is_bounce' => true,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'started_at' => CarbonImmutable::parse('2026-08-22 09:00:00', 'UTC'),
        'last_seen_at' => CarbonImmutable::parse('2026-08-22 09:03:00', 'UTC'),
        'duration_seconds' => 180,
        'is_bounce' => false,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'started_at' => CarbonImmutable::parse('2026-08-21 08:00:00', 'UTC'),
        'last_seen_at' => CarbonImmutable::parse('2026-08-21 08:00:30', 'UTC'),
        'duration_seconds' => 30,
        'is_bounce' => true,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'started_at' => CarbonImmutable::parse('2026-08-21 09:00:00', 'UTC'),
        'last_seen_at' => CarbonImmutable::parse('2026-08-21 09:01:30', 'UTC'),
        'duration_seconds' => 90,
        'is_bounce' => true,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'active-a',
        'started_at' => now()->subMinutes(4),
        'last_seen_at' => now()->subMinutes(4),
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'active-b',
        'started_at' => now()->subMinute(),
        'last_seen_at' => now()->subMinute(),
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'visitor_id' => 'previous-active',
        'started_at' => now()->subMinutes(6),
        'last_seen_at' => now()->subMinutes(6),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['range' => 'yesterday']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.metrics.activeVisitors', 2)
        ->where('analytics.metrics.visitors', 2)
        ->where('analytics.metrics.pageviews', 3)
        ->where('analytics.metrics.bounceRate', 50)
        ->where('analytics.metrics.viewsPerVisitor', 1.5)
        ->where('analytics.metrics.averageDuration', 120)
        ->where('analytics.metricTrends.activeVisitors.previous', 1)
        ->where('analytics.metricTrends.activeVisitors.change', 100)
        ->where('analytics.metricTrends.activeVisitors.series', [0, 0, 0, 0, 1, 2])
        ->where('analytics.metricTrends.visitors.previous', 1)
        ->where('analytics.metricTrends.visitors.change', 100)
        ->has('analytics.metricTrends.visitors.series', 24)
        ->where('analytics.metricTrends.pageviews.previous', 2)
        ->where('analytics.metricTrends.pageviews.change', 50)
        ->where('analytics.metricTrends.bounceRate.previous', 100)
        ->where('analytics.metricTrends.bounceRate.change', -50)
        ->has('analytics.metricTrends.bounceRate.series', 24)
        ->where('analytics.metricTrends.viewsPerVisitor.previous', 2)
        ->where('analytics.metricTrends.viewsPerVisitor.change', -25)
        ->where('analytics.metricTrends.averageDuration.previous', 60)
        ->where('analytics.metricTrends.averageDuration.change', 100)
        ->where('analytics.metricTrends.conversions.previous', 0)
        ->where('analytics.metricTrends.conversions.change', 0)
        ->where('analytics.comparison.available', true)
        ->has('analytics.metricTrends.averageDuration.series', 24));
});

test('dashboard groups AI referral visits by provider', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);

    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'referrer_host' => 'chatgpt.com',
        'utm_source' => null,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'referrer_host' => null,
        'utm_source' => 'CLAUDE',
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'referrer_host' => 'www.perplexity.ai',
        'utm_source' => null,
    ]);
    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'referrer_host' => 'chatgpt.com',
        'utm_source' => 'perplexity',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.aiReferrals.totalVisits', 4)
        ->has('analytics.aiReferrals.sources', 3)
        ->where('analytics.aiReferrals.sources.0.label', 'Perplexity')
        ->where('analytics.aiReferrals.sources.0.value', 2)
        ->where('analytics.aiReferrals.sources.1.label', 'ChatGPT')
        ->where('analytics.aiReferrals.sources.1.value', 1)
        ->where('analytics.aiReferrals.sources.2.label', 'Claude')
        ->where('analytics.aiReferrals.sources.2.value', 1));
});

test('dashboard treats configured domain referrers as direct traffic', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->domains()->create(['domain' => 'pastesheet.com', 'is_verified' => true]);

    AnalyticsSession::factory()->create([
        'project_id' => $project->id,
        'referrer_host' => 'www.pastesheet.com',
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.sources', [
            ['label' => 'Direct', 'value' => 1],
        ]));
});

test('dashboard places today pageviews in the project timezone timeseries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-23 12:30:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'Asia/Manila',
    ]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);
    AnalyticsEvent::factory()->create([
        'project_id' => $project->id,
        'event_name' => 'page_view',
        'occurred_at' => CarbonImmutable::parse('2026-08-23 11:15:00', 'UTC'),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['range' => 'today']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.metrics.pageviews', 1)
        ->where('analytics.range.interval', 'hour')
        ->has('analytics.timeseries', 21)
        ->where('analytics.timeseries.19.label', '7 PM')
        ->where('analytics.timeseries.19.pageviews', 1)
        ->where('analytics.timeseries.19.visitors', 1));
});

test('PostgreSQL connections use UTC for analytics timestamps', function () {
    expect(config('database.connections.pgsql.timezone'))->toBe('UTC');
});

test('dashboard groups :dataset traffic into hourly buckets', function (string $range, string $occurredAt, int $expectedBucketCount, int $eventBucket, string $expectedLabel) {
    $this->travelTo(CarbonImmutable::parse('2026-08-23 15:30:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'UTC',
    ]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);
    AnalyticsEvent::factory()->create([
        'project_id' => $project->id,
        'event_name' => 'page_view',
        'occurred_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard', ['range' => $range]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.range.interval', 'hour')
        ->has('analytics.timeseries', $expectedBucketCount)
        ->where("analytics.timeseries.$eventBucket.label", $expectedLabel)
        ->where("analytics.timeseries.$eventBucket.pageviews", 1)
        ->where("analytics.timeseries.$eventBucket.visitors", 1));
})->with([
    'today' => ['today', '2026-08-23 14:15:00', 16, 14, '2 PM'],
    'yesterday' => ['yesterday', '2026-08-22 09:15:00', 24, 9, '9 AM'],
]);

test('dashboard groups :dataset traffic into daily buckets', function (string $range, int $expectedBucketCount) {
    $this->travelTo(CarbonImmutable::parse('2026-08-23 15:30:00', 'UTC'));

    $user = User::factory()->create(['email_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'UTC',
    ]);
    $project->domains()->create(['domain' => 'example.test', 'is_verified' => true]);

    $response = $this->actingAs($user)->get(route('dashboard', ['range' => $range]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('analytics.range.interval', 'day')
        ->has('analytics.timeseries', $expectedBucketCount)
        ->where('analytics.timeseries.0.label', match ($range) {
            '7d' => 'Aug 17',
            '30d' => 'Jul 25',
            'month' => 'Aug 1',
        }));
})->with([
    'last 7 days' => ['7d', 7],
    'last 30 days' => ['30d', 30],
    'this month' => ['month', 23],
]);
