<?php

use App\Jobs\EvaluateInsightOutcomes;
use App\Jobs\RunAiVisibilityScan;
use App\Jobs\StartSearchConsoleSync;
use App\Models\Project;
use App\Models\SearchConsoleConnection;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('analytics:prune')->dailyAt('02:30');
Schedule::command('analytics:rollups --granularity=hour')->hourlyAt(5)->withoutOverlapping(30)->onOneServer();
Schedule::command('analytics:rollups --granularity=day')->dailyAt('03:00')->withoutOverlapping(30)->onOneServer();
Schedule::command('analytics:geoip:update')
    ->weeklyOn(1, '03:15')
    ->withoutOverlapping(30)
    ->onOneServer();
Schedule::call(function (): void {
    EvaluateInsightOutcomes::dispatch();
})->dailyAt('05:00')->name('evaluate-insight-outcomes')->withoutOverlapping(30)->onOneServer();
Schedule::call(function (): void {
    Project::query()
        ->where('is_active', true)
        ->whereHas('domains', fn ($query) => $query->where('is_verified', true))
        ->cursor()
        ->each(function (Project $project): void {
            $scan = $project->aiVisibilityScans()->create(['status' => 'queued']);
            RunAiVisibilityScan::dispatch($project, $scan);
        });
})->weeklyOn(1, '04:00')->name('ai-visibility-scan')->withoutOverlapping(30)->onOneServer();
Schedule::call(function (): void {
    SearchConsoleConnection::query()
        ->whereIn('status', ['connected', 'error'])
        ->cursor()
        ->each(fn (SearchConsoleConnection $connection) => StartSearchConsoleSync::dispatch($connection->getKey()));
})->dailyAt('04:30')->name('search-console-sync')->withoutOverlapping(30)->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
