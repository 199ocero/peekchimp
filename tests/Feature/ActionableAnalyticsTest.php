<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Funnel;
use App\Models\Goal;
use App\Models\Project;
use App\Models\User;
use App\Services\Analytics\BrowserSignalAnalyticsService;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Analytics\GoalAnalyticsService;
use App\Services\Analytics\GoalConversionService;
use App\Services\Analytics\ProductInvestigationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('event and URL goals record one conversion per session', function () {
    $project = Project::factory()->create();
    $eventGoal = Goal::factory()->for($project)->create(['type' => 'event', 'event_name' => 'signup_completed']);
    $urlGoal = Goal::factory()->for($project)->create(['name' => 'Thank you', 'type' => 'url', 'event_name' => null, 'path' => '/thanks']);
    $service = app(GoalConversionService::class);
    $occurredAt = CarbonImmutable::now();

    foreach ([
        ['event_id' => '11111111-1111-4111-8111-111111111111', 'event_name' => 'signup_completed', 'path' => '/pricing'],
        ['event_id' => '22222222-2222-4222-8222-222222222222', 'event_name' => 'page_view', 'path' => '/thanks'],
    ] as $data) {
        AnalyticsEvent::factory()->for($project)->create([
            'event_id' => $data['event_id'],
            'event_name' => $data['event_name'],
            'session_id' => 'session-one',
            'path' => $data['path'],
            'occurred_at' => $occurredAt,
        ]);
        $service->record($project, 'session-one', [...$data, 'properties' => [], 'occurred_at' => $occurredAt]);
    }
    $service->record($project, 'session-one', ['event_id' => '11111111-1111-4111-8111-111111111111', 'event_name' => 'signup_completed', 'path' => '/pricing', 'properties' => [], 'occurred_at' => $occurredAt]);

    expect($eventGoal->conversions()->count())->toBe(1)
        ->and($urlGoal->conversions()->count())->toBe(1);
});

test('funnel analytics counts ordered session progression and drop-off', function () {
    $project = Project::factory()->create();
    $funnel = Funnel::factory()->for($project)->create();
    $funnel->steps()->createMany([
        ['position' => 1, 'name' => 'Landing', 'type' => 'url', 'path' => '/', 'path_operator' => 'exact'],
        ['position' => 2, 'name' => 'Signup', 'type' => 'event', 'event_name' => 'signup_completed', 'path_operator' => 'exact'],
    ]);
    foreach (['session-a', 'session-b'] as $sessionId) {
        AnalyticsSession::factory()->for($project)->create(['session_id' => $sessionId, 'started_at' => now(), 'last_seen_at' => now()]);
        AnalyticsEvent::factory()->for($project)->create(['session_id' => $sessionId, 'event_name' => 'page_view', 'path' => '/', 'occurred_at' => now()->subMinute()]);
    }
    AnalyticsEvent::factory()->for($project)->create(['session_id' => 'session-a', 'event_name' => 'signup_completed', 'path' => '/', 'occurred_at' => now()]);

    $summary = app(FunnelAnalyticsService::class)->summary($funnel->fresh(), CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addHour());

    expect($summary['steps'][0]['users'])->toBe(2)
        ->and($summary['steps'][0]['progressed'])->toBe(1)
        ->and($summary['steps'][1]['users'])->toBe(1)
        ->and($summary['conversionRate'])->toBe(50.0);
});

test('browser signals return aggregate p75, failure, and interaction data', function () {
    $project = Project::factory()->create();
    $now = CarbonImmutable::now();

    for ($index = 0; $index < 20; $index++) {
        $sessionId = 'signal-session-'.$index;
        AnalyticsSession::factory()->for($project)->create([
            'session_id' => $sessionId,
            'started_at' => $now->subMinutes(10),
            'last_seen_at' => $now,
        ]);
        AnalyticsEvent::factory()->for($project)->create([
            'session_id' => $sessionId,
            'event_name' => 'page_view',
            'path' => '/checkout',
            'occurred_at' => $now->subMinutes(5),
        ]);
        AnalyticsEvent::factory()->for($project)->create([
            'session_id' => $sessionId,
            'event_name' => 'web_vital.lcp',
            'path' => '/checkout',
            'properties' => ['value_ms' => $index < 10 ? 2000 : 3000],
            'occurred_at' => $now->subMinutes(4),
        ]);
        AnalyticsEvent::factory()->for($project)->create([
            'session_id' => $sessionId,
            'event_name' => 'autocapture.click',
            'path' => '/checkout',
            'properties' => ['kind' => 'submit', 'target' => 'pay-now'],
            'occurred_at' => $now->subMinutes(3),
        ]);
        if ($index >= 10) {
            AnalyticsEvent::factory()->for($project)->create([
                'session_id' => $sessionId,
                'event_name' => 'autocapture.submit',
                'path' => '/checkout',
                'properties' => ['target' => 'checkout-form'],
                'occurred_at' => $now->subMinutes(2),
            ]);
        }
        if ($index < 10) {
            AnalyticsEvent::factory()->for($project)->create([
                'session_id' => $sessionId,
                'event_name' => 'request_failure',
                'path' => '/checkout',
                'properties' => [
                    'method' => 'POST',
                    'request_path' => '/api/checkout',
                    'status' => 500,
                    'fingerprint' => 'checkout-failure',
                ],
                'occurred_at' => $now->subMinute(),
            ]);
        }
    }

    $signals = app(BrowserSignalAnalyticsService::class)->page($project, '/checkout', $now->subDay(), $now->addMinute());

    expect($signals['status'])->toBe('ok')
        ->and($signals['performance']['lcp'])->toMatchArray(['samples' => 20, 'p75Ms' => 3000])
        ->and($signals['behavior']['submitAttempts'])->toBe(20)
        ->and($signals['behavior']['submitAttemptsWithoutSubmission'])->toBe(10)
        ->and($signals['failures']['affectedSessions'])->toBe(10)
        ->and($signals['failures']['requestFailures'][0]['sessions'])->toBe(10);
});

test('funnel investigation identifies a materially worse segment', function () {
    $project = Project::factory()->create();
    $funnel = Funnel::factory()->for($project)->create();
    $funnel->steps()->createMany([
        ['position' => 1, 'name' => 'Landing', 'type' => 'url', 'path' => '/', 'path_operator' => 'exact'],
        ['position' => 2, 'name' => 'Signup', 'type' => 'event', 'event_name' => 'signup_completed', 'path_operator' => 'exact'],
    ]);
    $now = CarbonImmutable::now();

    for ($index = 0; $index < 40; $index++) {
        $sessionId = 'funnel-investigation-'.$index;
        $device = $index < 20 ? 'mobile' : 'desktop';
        AnalyticsSession::factory()->for($project)->create([
            'session_id' => $sessionId,
            'device' => $device,
            'started_at' => $now->subMinutes(10),
            'last_seen_at' => $now,
        ]);
        AnalyticsEvent::factory()->for($project)->create([
            'session_id' => $sessionId,
            'event_name' => 'page_view',
            'path' => '/',
            'occurred_at' => $now->subMinutes(5),
        ]);
        if ($index >= 20) {
            AnalyticsEvent::factory()->for($project)->create([
                'session_id' => $sessionId,
                'event_name' => 'signup_completed',
                'path' => '/',
                'occurred_at' => $now->subMinutes(4),
            ]);
        }
    }

    $investigation = app(FunnelAnalyticsService::class)->investigate($funnel->fresh('steps'), $now->subDay(), $now->addMinute());

    expect($investigation['largestDropOff'])->toMatchArray(['users' => 40, 'dropOff' => 20, 'dropOffPercentage' => 50.0])
        ->and($investigation['segments'][0])->toMatchArray(['dimension' => 'device', 'value' => 'mobile', 'gap' => 100.0]);
});

test('friction investigation reports only thresholded aggregate findings', function () {
    $project = Project::factory()->create();
    $now = CarbonImmutable::now();

    for ($index = 0; $index < 20; $index++) {
        $sessionId = 'friction-session-'.$index;
        AnalyticsSession::factory()->for($project)->create([
            'session_id' => $sessionId,
            'started_at' => $now->subMinutes(10),
            'last_seen_at' => $now,
        ]);
        AnalyticsEvent::factory()->for($project)->create([
            'session_id' => $sessionId,
            'event_name' => 'page_view',
            'path' => '/checkout',
            'occurred_at' => $now->subMinutes(5),
        ]);
        AnalyticsEvent::factory()->for($project)->create([
            'session_id' => $sessionId,
            'event_name' => 'web_vital.lcp',
            'path' => '/checkout',
            'properties' => ['value_ms' => 3000],
            'occurred_at' => $now->subMinutes(4),
        ]);
    }

    $findings = app(ProductInvestigationService::class)->findFriction($project, $now->subDay(), $now->addMinute());

    expect($findings['status'])->toBe('ok')
        ->and($findings['findings'][0])->toMatchArray(['category' => 'performance', 'path' => '/checkout', 'affectedSessions' => 20]);
});

test('goal analytics reports session conversion rate', function () {
    $project = Project::factory()->create();
    $goal = Goal::factory()->for($project)->create();
    AnalyticsSession::factory()->for($project)->count(4)->create(['started_at' => now()]);
    $goal->conversions()->createMany([
        ['project_id' => $project->id, 'session_id' => 'one', 'occurred_at' => now()],
        ['project_id' => $project->id, 'session_id' => 'two', 'occurred_at' => now()],
    ]);

    expect(app(GoalAnalyticsService::class)->summary($goal, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()))
        ->toMatchArray(['conversions' => 2, 'visits' => 4, 'conversionRate' => 50.0]);
});

test('dashboard exposes actionable outcome sections', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $project->goals()->create(['name' => 'Signup', 'type' => 'event', 'event_name' => 'signup_completed']);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->has('analytics.metrics.conversions')
        ->has('analytics.metrics.conversionRate')
        ->has('analytics.goals')
        ->has('analytics.importantActions')
        ->has('analytics.aiTraffic'));
});
