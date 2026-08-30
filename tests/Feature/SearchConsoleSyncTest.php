<?php

use App\Contracts\SearchConsoleClient;
use App\Exceptions\SearchConsoleReconnectRequiredException;
use App\Jobs\StartSearchConsoleSync;
use App\Jobs\SyncSearchConsoleDay;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use App\Models\User;
use App\Services\SearchConsole\SearchConsoleSyncManager;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;

it('imports property page and query metrics for one finalized day', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $connection = SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $user->getKey(),
    ]);
    $date = CarbonImmutable::parse('2026-08-20', 'UTC');
    $client = Mockery::mock(SearchConsoleClient::class);
    $client->shouldReceive('query')->times(4)->andReturnUsing(
        fn (SearchConsoleConnection $received, CarbonImmutable $receivedDate, array $dimensions): array => match ($dimensions) {
            [] => [['clicks' => 12, 'impressions' => 120, 'position' => 4.5]],
            ['page'] => [['keys' => ['https://example.com/guides/seo'], 'clicks' => 8, 'impressions' => 80, 'position' => 3.2]],
            ['query'] => [['keys' => ['simple analytics'], 'clicks' => 4, 'impressions' => 40, 'position' => 6.1]],
            ['query', 'page'] => [['keys' => ['simple analytics', 'https://example.com/guides/seo'], 'clicks' => 4, 'impressions' => 40, 'position' => 6.1]],
        },
    );

    (new SearchConsoleSyncManager($client))->syncDay($connection, $date);

    expect(SearchConsoleMetric::query()->where('project_id', $project->getKey())->count())->toBe(4)
        ->and(SearchConsoleMetric::query()->where('dimension_type', 'page')->sole()->normalized_path)->toBe('/guides/seo')
        ->and(SearchConsoleMetric::query()->where('dimension_type', 'query')->sole()->dimension_value)->toBe('simple analytics')
        ->and(SearchConsoleMetric::query()->where('dimension_type', 'query_page')->sole()->normalized_path)->toBe('/guides/seo');
});

it('keeps stale metrics and marks the connection when Google requires reconnection', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $connection = SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $user->getKey(),
        'data_through' => '2026-08-19',
    ]);
    SearchConsoleMetric::factory()->for($project)->create(['report_date' => '2026-08-19']);
    $syncManager = Mockery::mock(SearchConsoleSyncManager::class);
    $syncManager->shouldReceive('syncDay')->once()->andThrow(
        new SearchConsoleReconnectRequiredException('Reconnect Google.'),
    );

    (new SyncSearchConsoleDay($connection->getKey(), '2026-08-20'))->handle($syncManager);

    expect($connection->refresh()->status)->toBe('reconnect_required')
        ->and($connection->access_token)->toBeNull()
        ->and(SearchConsoleMetric::query()->where('project_id', $project->getKey())->count())->toBe(1);
});

it('backfills query page pairs for an existing connection', function () {
    Bus::fake();
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $connection = SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $user->getKey(),
        'data_through' => '2026-08-19',
    ]);
    SearchConsoleMetric::factory()->for($project)->create([
        'report_date' => '2026-08-19',
        'dimension_type' => 'property',
    ]);
    $syncManager = Mockery::mock(SearchConsoleSyncManager::class);
    $syncManager->shouldReceive('latestDataDate')->once()->andReturn(CarbonImmutable::parse('2026-08-20', 'UTC'));

    (new StartSearchConsoleSync($connection->getKey()))->handle($syncManager);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 90);
});
