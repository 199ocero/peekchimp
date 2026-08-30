<?php

namespace App\Jobs;

use App\Exceptions\SearchConsoleReconnectRequiredException;
use App\Models\SearchConsoleConnection;
use App\Services\SearchConsole\SearchConsoleSyncManager;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncSearchConsoleDay implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $connectionId, public string $date)
    {
        $this->onQueue('analytics');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(SearchConsoleSyncManager $syncManager): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $connection = SearchConsoleConnection::query()->find($this->connectionId);

        if ($connection === null || $connection->status === 'reconnect_required') {
            return;
        }

        try {
            $syncManager->syncDay($connection, CarbonImmutable::parse($this->date, 'UTC'));
        } catch (SearchConsoleReconnectRequiredException $exception) {
            $connection->update([
                'status' => 'reconnect_required',
                'access_token' => null,
                'sync_started_at' => null,
                'last_error' => $exception->getMessage(),
            ]);
            $this->batch()?->cancel();
        }
    }

    public function failed(?Throwable $exception): void
    {
        SearchConsoleConnection::query()
            ->whereKey($this->connectionId)
            ->where('status', '!=', 'reconnect_required')
            ->update([
                'status' => 'error',
                'last_error' => mb_substr($exception?->getMessage() ?? 'A Search Console import failed.', 0, 2000),
            ]);
    }
}
