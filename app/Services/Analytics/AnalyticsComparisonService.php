<?php

namespace App\Services\Analytics;

use Illuminate\Support\Str;

class AnalyticsComparisonService
{
    /**
     * Compare a compact metric snapshot. Non-numeric values are ignored.
     *
     * @param  array<string, int|float>  $current
     * @param  array<string, int|float>  $previous
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    public function comparePeriods(array $current, array $previous, array $options = []): array
    {
        $changes = [];

        foreach ($current as $metric => $value) {
            $candidate = $this->compare(
                $value,
                $previous[$metric] ?? 0,
                $metric,
                [...$options, 'sample' => ($options['sample'] ?? 0) ?: (int) round(abs($value) + abs((float) ($previous[$metric] ?? 0)))],
            );

            if ($candidate !== null) {
                $changes[] = $candidate;
            }
        }

        return $changes;
    }

    /**
     * Compare two equivalent periods and return a meaningful candidate.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function compare(
        int|float $current,
        int|float $previous,
        string $metric,
        array $options = [],
    ): ?array {
        $current = (float) $current;
        $previous = (float) $previous;
        $percentage = $this->percentageChange($current, $previous);
        $isRate = (bool) ($options['is_rate'] ?? Str::endsWith(Str::lower($metric), ['rate', 'percentage', 'ctr']));
        $sample = (int) ($options['sample'] ?? max($current, $previous));

        if ($isRate) {
            $minimumDenominator = (int) ($options['minimum_denominator'] ?? config('analytics.change_detection.minimum_rate_denominator', 50));
            $pointChange = abs($current - $previous);

            if ($sample < $minimumDenominator || $pointChange < (float) config('analytics.change_detection.minimum_rate_point_change', 5.0)) {
                return null;
            }
        } else {
            $minimumCount = (int) ($options['minimum_count'] ?? config('analytics.change_detection.minimum_count', 10));
            $minimumCombined = (int) ($options['minimum_combined_count'] ?? config('analytics.change_detection.minimum_combined_count', 40));
            $minimumPercentage = (float) ($options['minimum_percentage'] ?? config('analytics.change_detection.minimum_percentage', 25.0));

            if ($sample < $minimumCount || ($current + $previous) < $minimumCombined) {
                return null;
            }

            if (($percentage !== null && abs($percentage) < $minimumPercentage) || ($percentage === null && $current <= 0)) {
                return null;
            }
        }

        $direction = $current > $previous ? 'increased' : ($current < $previous ? 'decreased' : 'unchanged');
        $confidence = $this->confidence((int) round($sample));

        return [
            'metric' => $metric,
            'current_value' => $this->number($current),
            'previous_value' => $this->number($previous),
            'percentage_change' => $percentage,
            'direction' => $direction,
            'confidence' => $confidence,
            'severity' => $this->severity($percentage, $isRate ? abs($current - $previous) : null),
            'category' => (string) ($options['category'] ?? 'traffic'),
            'label' => (string) ($options['label'] ?? Str::headline($metric)),
            'metadata' => (array) ($options['metadata'] ?? []),
        ];
    }

    public function percentageChange(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    public function confidence(int $sample): string
    {
        return match (true) {
            $sample >= 200 => 'high',
            $sample >= 50 => 'medium',
            default => 'low',
        };
    }

    private function severity(?float $percentage, ?float $pointChange): string
    {
        $signal = max(abs((float) ($percentage ?? 0)), (float) ($pointChange ?? 0) * 4);

        return match (true) {
            $signal >= 75 => 'critical',
            $signal >= 45 => 'warning',
            default => 'info',
        };
    }

    private function number(float $value): int|float
    {
        return fmod($value, 1.0) === 0.0 ? (int) $value : round($value, 2);
    }
}
