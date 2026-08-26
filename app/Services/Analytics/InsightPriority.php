<?php

namespace App\Services\Analytics;

class InsightPriority
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    public function sort(array $candidates): array
    {
        usort($candidates, function (array $first, array $second): int {
            $severityComparison = $this->severityRank((string) $second['severity'])
                <=> $this->severityRank((string) $first['severity']);

            if ($severityComparison !== 0) {
                return $severityComparison;
            }

            $confidenceComparison = $this->confidenceRank((string) $second['confidence'])
                <=> $this->confidenceRank((string) $first['confidence']);

            if ($confidenceComparison !== 0) {
                return $confidenceComparison;
            }

            $changeComparison = abs((float) ($second['percentage_change'] ?? 0))
                <=> abs((float) ($first['percentage_change'] ?? 0));

            return $changeComparison !== 0
                ? $changeComparison
                : strcasecmp((string) $first['label'], (string) $second['label']);
        });

        return $candidates;
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 3,
            'warning' => 2,
            default => 1,
        };
    }

    private function confidenceRank(string $confidence): int
    {
        return match ($confidence) {
            'high' => 3,
            'medium' => 2,
            default => 1,
        };
    }
}
