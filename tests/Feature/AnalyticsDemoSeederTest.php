<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\AnalyticsDemoSeeder;

test('analytics demo seeder populates the project attached to the requested domain', function () {
    $user = User::factory()->create();
    $otherProject = Project::factory()->create(['user_id' => $user->id]);
    $otherProject->domains()->create(['domain' => 'other.example.test']);
    $fallbackProject = Project::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'Asia/Manila',
    ]);
    $project->domains()->create([
        'domain' => 'demo.example.test',
        'is_verified' => true,
    ]);

    $this->seed(AnalyticsDemoSeeder::class);

    $events = AnalyticsEvent::query()->whereBelongsTo($project)->get();
    $sessions = AnalyticsSession::query()->whereBelongsTo($project)->get();
    $platforms = $events->pluck('platform')->unique()->values()->all();
    $aiReferrers = $sessions->pluck('referrer_host')->filter()->values()->all();

    expect($events)->not->toBeEmpty()
        ->and($sessions->count())->toBe(240)
        ->and($events->where('event_name', 'page_view'))->not->toBeEmpty()
        ->and($events->pluck('event_name')->all())->toContain('purchase', 'signup', 'newsletter_subscribe')
        ->and($platforms)->toContain('web', 'ios', 'android', 'react-native', 'flutter')
        ->and($aiReferrers)->toContain(
            'chatgpt.com',
            'claude.ai',
            'perplexity.ai',
            'gemini.google.com',
            'copilot.microsoft.com',
        )
        ->and($sessions->pluck('country')->unique()->count())->toBe(10)
        ->and($sessions->pluck('device')->unique()->count())->toBe(3)
        ->and($sessions->pluck('browser')->unique()->count())->toBe(5)
        ->and($sessions->pluck('operating_system')->unique()->count())->toBe(5)
        ->and($sessions->pluck('utm_campaign')->filter()->unique()->count())->toBe(5);

    $eventCount = $events->count();
    $sessionCount = $sessions->count();

    $this->seed(AnalyticsDemoSeeder::class);

    expect(AnalyticsEvent::query()->whereBelongsTo($project)->count())->toBe($eventCount)
        ->and(AnalyticsSession::query()->whereBelongsTo($project)->count())->toBe($sessionCount)
        ->and(AnalyticsEvent::query()->whereBelongsTo($fallbackProject)->count())->toBe(0);
});

test('analytics demo seeder falls back to project two when the requested domain is missing', function () {
    $user = User::factory()->create();
    $firstProject = Project::factory()->create(['user_id' => $user->id]);
    $firstProject->domains()->create(['domain' => 'first.example.test']);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'timezone' => 'Asia/Manila',
    ]);

    $this->seed(AnalyticsDemoSeeder::class);

    expect(AnalyticsSession::query()->whereBelongsTo($project)->count())->toBe(240)
        ->and(AnalyticsEvent::query()->whereBelongsTo($firstProject)->count())->toBe(0);
});
