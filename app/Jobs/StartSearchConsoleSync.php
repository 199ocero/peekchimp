<?php

namespace App\Jobs;

use App\Exceptions\SearchConsoleReconnectRequiredException;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use App\Services\SearchConsole\SearchConsoleSyncManager;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

class StartSearchConsoleSync implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public int $connectionId)
    {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(SearchConsoleSyncManager $syncManager): void
    {
        $connection = SearchConsoleConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->status === 'reconnect_required') {
            return;
        }

        $connection->update([
            'status' => 'syncing',
            'sync_started_at' => now(),
            'last_error' => null,
        ]);

        try {
            $latest = $syncManager->latestDataDate($connection);
        } catch (SearchConsoleReconnectRequiredException $exception) {
            $connection->update([
                'status' => 'reconnect_required',
                'access_token' => null,
                'sync_started_at' => null,
                'last_error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($latest === null) {
            $connection->update([
                'status' => 'connected',
                'sync_started_at' => null,
                'last_synced_at' => now(),
            ]);

            return;
        }

        $firstDate = $this->firstDate($connection, $latest);
        $jobs = [];

        for ($date = $firstDate; $date->lessThanOrEqualTo($latest); $date = $date->addDay()) {
            $jobs[] = new SyncSearchConsoleDay($connection->getKey(), $date->toDateString());
        }

        $connectionId = $connection->getKey();
        $latestDate = $latest->toDateString();
        $oldestDate = $latest->subDays((int) config('analytics.search_console.backfill_days', 90) - 1)->toDateString();
        $batch = Bus::batch($jobs)
            ->name('Google Search Console: connection '.$connectionId)
            ->then(static function (Batch $batch) use ($connectionId, $latestDate, $oldestDate): void {
                SearchConsoleMetric::query()
                    ->where('project_id', SearchConsoleConnection::query()->whereKey($connectionId)->value('project_id'))
                    ->whereDate('report_date', '<', $oldestDate)
                    ->delete();
                SearchConsoleConnection::query()->whereKey($connectionId)->update([
                    'status' => 'connected',
                    'data_through' => $latestDate,
                    'sync_started_at' => null,
                    'last_synced_at' => now(),
                    'last_error' => null,
                ]);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($connectionId): void {
                SearchConsoleConnection::query()
                    ->whereKey($connectionId)
                    ->where('status', '!=', 'reconnect_required')
                    ->update([
                        'status' => 'error',
                        'sync_started_at' => null,
                        'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    ]);
            })
            ->onQueue('analytics')
            ->dispatch();

        $connection->update(['sync_batch_id' => $batch->id]);
    }

    public function failed(?Throwable $exception): void
    {
        SearchConsoleConnection::query()
            ->whereKey($this->connectionId)
            ->where('status', '!=', 'reconnect_required')
            ->update([
                'status' => 'error',
                'sync_started_at' => null,
                'last_error' => mb_substr($exception?->getMessage() ?? 'Search Console sync could not start.', 0, 2000),
            ]);
    }

    private function firstDate(SearchConsoleConnection $connection, CarbonImmutable $latest): CarbonImmutable
    {
        if ($connection->data_through === null
            || ! SearchConsoleMetric::query()
                ->where('project_id', $connection->project_id)
                ->where('dimension_type', 'query_page')
                ->exists()) {
            return $latest->subDays((int) config('analytics.search_console.backfill_days', 90) - 1);
        }

        return $latest->subDays((int) config('analytics.search_console.resync_days', 7) - 1);
    }
}
