<?php

namespace App\Services\Mcp;

use App\Models\Project;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class McpReportRange
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable, dashboardFilters: array<string, string>}
     */
    public function resolve(Project $project, array $input): array
    {
        $range = (string) ($input['range'] ?? '30d');
        $today = CarbonImmutable::now($project->timezone)->startOfDay();

        if ($range === 'today') {
            return $this->make($range, 'Today', $today, $today->endOfDay());
        }

        if ($range === 'yesterday') {
            $day = $today->subDay();

            return $this->make($range, 'Yesterday', $day, $day->endOfDay());
        }

        if ($range === '7d') {
            return $this->make($range, 'Last 7 days', $today->subDays(6), $today->endOfDay());
        }

        if ($range === '30d') {
            return $this->make($range, 'Last 30 days', $today->subDays(29), $today->endOfDay());
        }

        if ($range === 'month') {
            return $this->make($range, 'This month', $today->startOfMonth(), $today->endOfDay());
        }

        if ($range !== 'custom') {
            throw new InvalidArgumentException('Range must be today, yesterday, 7d, 30d, month, or custom.');
        }

        $from = $this->parseDate($input['from'] ?? null, 'from', $project->timezone);
        $to = $this->parseDate($input['to'] ?? null, 'to', $project->timezone);

        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException('The custom range start must be before its end.');
        }

        if ($to->greaterThan($today)) {
            throw new InvalidArgumentException('The report range cannot include future dates.');
        }

        $maximumDays = max(1, (int) config('analytics.retention_days', 90));

        if ($from->diffInDays($to) + 1 > $maximumDays) {
            throw new InvalidArgumentException("The report range cannot exceed {$maximumDays} days.");
        }

        return $this->make(
            $range,
            $from->format('M j').' – '.$to->format('M j, Y'),
            $from,
            $to->endOfDay(),
        );
    }

    /**
     * @return array{key: string, label: string, from: CarbonImmutable, to: CarbonImmutable, dashboardFilters: array<string, string>}
     */
    private function make(string $key, string $label, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'from' => $from,
            'to' => $to,
            'dashboardFilters' => $key === 'custom'
                ? ['range' => 'custom', 'from' => $from->toDateString(), 'to' => $to->toDateString()]
                : ['range' => $key],
        ];
    }

    private function parseDate(mixed $value, string $field, string $timezone): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException("The {$field} date must use YYYY-MM-DD format.");
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            if ($date === null || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException("The {$field} date is invalid.");
            }

            return $date;
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("The {$field} date is invalid.", 0, $exception);
        }
    }
}
