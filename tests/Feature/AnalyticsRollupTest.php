<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Services\Analytics\AnalyticsAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('rollups aggregate page views, visits, visitors and dimensions', function () {
    $project = Project::factory()->create(['timezone' => 'UTC']);
    $time = CarbonImmutable::parse('2026-08-25 10:15:00', 'UTC');
    AnalyticsEvent::factory()->for($project)->create(['event_name' => 'page_view', 'visitor_id' => 'visitor-one', 'session_id' => 'session-one', 'occurred_at' => $time, 'referrer_host' => 'google.com']);
    AnalyticsSession::factory()->for($project)->create(['session_id' => 'session-one', 'visitor_id' => 'visitor-one', 'started_at' => $time, 'last_seen_at' => $time, 'referrer_host' => 'google.com']);

    app(AnalyticsAggregationService::class)->rebuild($project, $time->subMinute(), $time->addMinute(), 'hour', true);

    $overall = $project->analyticsRollups()->where('dimension', 'overall')->sole();
    expect($overall->pageviews)->toBe(1)->and($overall->visits)->toBe(1)->and($overall->visitors)->toBe(1);
    expect($project->analyticsRollups()->where('dimension', 'source')->where('dimension_value', 'Google')->sole()->visits)->toBe(1);
});

test('rollup visitor counts stay tied to page views when a session has only custom events', function () {
    $project = Project::factory()->create(['timezone' => 'UTC']);
    $time = CarbonImmutable::parse('2026-08-25 10:15:00', 'UTC');
    AnalyticsEvent::factory()->for($project)->create([
        'event_name' => 'signup_clicked',
        'visitor_id' => 'visitor-one',
        'session_id' => 'session-one',
        'occurred_at' => $time,
    ]);
    AnalyticsSession::factory()->for($project)->create([
        'session_id' => 'session-one',
        'visitor_id' => 'visitor-one',
        'pageviews' => 0,
        'custom_events' => 1,
        'started_at' => $time,
        'last_seen_at' => $time,
    ]);

    app(AnalyticsAggregationService::class)->rebuild($project, $time->subMinute(), $time->addMinute(), 'hour', true);

    expect($project->analyticsRollups()->where('dimension', 'overall')->sole()->visitors)->toBe(0);
});
