<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Analytics\AnalyticsAggregationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:rollups {--project= : Project ID} {--from= : ISO date/time} {--to= : ISO date/time} {--granularity=hour}')]
#[Description('Build closed hourly or daily analytics rollups')]
class RebuildAnalyticsRollups extends Command
{
    public function handle(AnalyticsAggregationService $aggregation): int
    {
        $projectId = $this->option('project');
        $projects = Project::query()->where('is_active', true);

        if ($projectId !== null) {
            $projects->whereKey((int) $projectId);
        }

        $granularity = (string) $this->option('granularity');
        $from = $this->dateOption('from') ?? (
            $granularity === 'day'
                ? CarbonImmutable::now('UTC')->subDays(2)
                : CarbonImmutable::now('UTC')->subHours(3)
        );
        $to = $this->dateOption('to');
        $total = 0;

        foreach ($projects->cursor() as $project) {
            $count = $aggregation->rebuild($project, $from, $to, $granularity);
            $total += $count;
            $this->line("Project {$project->getKey()}: {$count} rollups");
        }

        $this->info("Built {$total} rollups.");

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            $this->warn("Ignoring invalid --{$name} value.");

            return null;
        }
    }
}
