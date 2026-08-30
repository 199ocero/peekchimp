<?php

namespace App\Services\SearchConsole;

use App\Contracts\SearchConsoleClient;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SearchConsoleSyncManager
{
    public function __construct(private readonly SearchConsoleClient $client) {}

    public function latestDataDate(SearchConsoleConnection $connection): ?CarbonImmutable
    {
        return $this->client->latestDataDate($connection);
    }

    public function syncDay(SearchConsoleConnection $connection, CarbonImmutable $date): void
    {
        $metrics = [];

        foreach ([
            ['type' => 'property', 'dimensions' => []],
            ['type' => 'page', 'dimensions' => ['page']],
            ['type' => 'query', 'dimensions' => ['query']],
            ['type' => 'query_page', 'dimensions' => ['query', 'page']],
        ] as $report) {
            foreach ($this->client->query($connection, $date, $report['dimensions']) as $row) {
                $dimensionValue = $report['type'] === 'property' ? '' : (string) data_get($row, 'keys.0', '');
                $pageUrl = match ($report['type']) {
                    'page' => $dimensionValue,
                    'query_page' => (string) data_get($row, 'keys.1', ''),
                    default => null,
                };
                $metrics[] = [
                    'project_id' => $connection->project_id,
                    'report_date' => $date->toDateString(),
                    'search_type' => 'web',
                    'dimension_type' => $report['type'],
                    'dimension_value' => $dimensionValue,
                    'dimension_hash' => sha1($report['type'] === 'query_page'
                        ? $dimensionValue."\0".$pageUrl
                        : $dimensionValue),
                    'normalized_path' => $pageUrl === null ? null : $this->pagePath($pageUrl),
                    'clicks' => max(0, (int) round((float) $row['clicks'])),
                    'impressions' => max(0, (int) round((float) $row['impressions'])),
                    'position' => isset($row['position']) ? round((float) $row['position'], 4) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::transaction(function () use ($connection, $date, $metrics): void {
            SearchConsoleMetric::query()
                ->where('project_id', $connection->project_id)
                ->whereDate('report_date', $date->toDateString())
                ->delete();

            foreach (array_chunk($metrics, 500) as $chunk) {
                SearchConsoleMetric::query()->insert($chunk);
            }
        });
    }

    private function pagePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
