<?php

namespace App\Services\Analytics;

use App\Enums\PublicDashboardSection;
use Illuminate\Support\Arr;

class PublicDashboardDataBuilder
{
    /**
     * @param  array<string, mixed>  $analytics
     * @param  array<int, string>  $sections
     * @return array<string, mixed>
     */
    public function build(array $analytics, array $sections): array
    {
        $public = [
            'range' => $analytics['range'],
            'comparison' => $analytics['comparison'],
        ];
        $metrics = $analytics['metrics'];

        if (in_array(PublicDashboardSection::Metrics->value, $sections, true)) {
            $metricKeys = [
                'pageviews',
                'visitors',
                'bounceRate',
                'averageDuration',
                'viewsPerVisitor',
            ];
            $public['metrics'] = Arr::only($metrics, $metricKeys);
            $public['metricTrends'] = Arr::only($analytics['metricTrends'], $metricKeys);
        }

        if (in_array(PublicDashboardSection::Traffic->value, $sections, true)) {
            $public['traffic'] = [
                'metrics' => [
                    'pageviews' => $metrics['pageviews'],
                    'visitors' => $metrics['visitors'],
                ],
                'timeseries' => $analytics['timeseries'],
            ];
        }

        if (in_array(PublicDashboardSection::Pages->value, $sections, true)) {
            $public['pages'] = [
                'total' => $metrics['pageviews'],
                'items' => $analytics['topPages'],
            ];
        }

        if (in_array(PublicDashboardSection::Acquisition->value, $sections, true)) {
            $public['acquisition'] = [
                'referrers' => $analytics['referrers'],
                'campaigns' => $analytics['campaigns'],
                'aiReferrals' => $analytics['aiReferrals'],
            ];
        }

        if (in_array(PublicDashboardSection::Audience->value, $sections, true)) {
            $public['audience'] = [
                'countryVisits' => $analytics['countryVisits'],
                'devices' => $analytics['devices'],
                'browsers' => $analytics['browsers'],
            ];
        }

        return $public;
    }
}
