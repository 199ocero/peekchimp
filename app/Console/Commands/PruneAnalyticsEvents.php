<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('analytics:prune {--days= : Override the configured retention window}')]
#[Description('Delete analytics data older than the configured retention window')]
class PruneAnalyticsEvents extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('analytics.retention_days', 90)));
        $cutoff = now()->subDays($days);
        $events = DB::table('events')->where('occurred_at', '<', $cutoff)->delete();
        $sessions = DB::table('analytics_sessions')->where('last_seen_at', '<', $cutoff)->delete();
        $conversions = DB::table('goal_conversions')->where('occurred_at', '<', $cutoff)->delete();
        $rollups = DB::table('analytics_rollups')->where('bucket_start', '<', $cutoff)->delete();
        $insights = DB::table('insights')->where('period_end', '<', $cutoff)->delete();
        $aiRuns = DB::table('ai_insight_runs')->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$events} events, {$sessions} sessions, {$conversions} conversions, {$rollups} rollups, {$insights} insights, and {$aiRuns} AI runs older than {$days} days.");

        return self::SUCCESS;
    }
}
