<?php

namespace App\Services\Analytics;

class DashboardInsightBuilder
{
    private const int MinimumVisits = 20;

    private const float HighSinglePageRate = 70.0;

    private const float HighDirectTrafficRate = 60.0;

    /**
     * @param  array<int, array{label: string, value: int}>  $referrers
     * @return array<int, array{type: string, tone: string, value: float|int}>
     */
    public function build(int $visits, float $singlePageRate, array $referrers, bool $hasComparisonData = true): array
    {
        if (! $hasComparisonData) {
            return [[
                'type' => 'comparison_pending',
                'tone' => 'neutral',
                'value' => $visits,
            ]];
        }

        if ($visits < self::MinimumVisits) {
            return [[
                'type' => 'insufficient_data',
                'tone' => 'neutral',
                'value' => $visits,
            ]];
        }

        $insights = [];

        if ($singlePageRate >= self::HighSinglePageRate) {
            $insights[] = [
                'type' => 'high_single_page_rate',
                'tone' => 'attention',
                'value' => $singlePageRate,
            ];
        }

        $directTrafficRate = $this->directTrafficRate($referrers);

        if ($directTrafficRate >= self::HighDirectTrafficRate) {
            $insights[] = [
                'type' => 'unattributed_traffic',
                'tone' => 'attention',
                'value' => $directTrafficRate,
            ];
        }

        if ($insights !== []) {
            return $insights;
        }

        return [[
            'type' => 'healthy_engagement',
            'tone' => 'positive',
            'value' => $singlePageRate,
        ]];
    }

    /**
     * @param  array<int, array{label: string, value: int}>  $referrers
     */
    private function directTrafficRate(array $referrers): float
    {
        $totalTraffic = array_sum(array_column($referrers, 'value'));

        if ($totalTraffic === 0) {
            return 0.0;
        }

        $directTraffic = 0;

        foreach ($referrers as $referrer) {
            if ($referrer['label'] === 'Direct') {
                $directTraffic = $referrer['value'];

                break;
            }
        }

        return round(($directTraffic / $totalTraffic) * 100, 1);
    }
}
