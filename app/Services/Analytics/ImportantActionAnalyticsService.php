<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\ImportantAction;
use App\Models\Project;
use Carbon\CarbonImmutable;

class ImportantActionAnalyticsService
{
    /**
     * @return array<int, array{id: int, name: string, eventName: string, pagePath: string|null, views: int, clicks: int, ctr: float}>
     */
    public function summarize(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $actions = ImportantAction::query()
            ->where('project_id', $project->getKey())
            ->where('is_active', true)
            ->get();
        $results = [];

        foreach ($actions as $action) {
            $base = AnalyticsEvent::query()
                ->where('project_id', $project->getKey())
                ->whereBetween('occurred_at', [$from->utc(), $to->utc()]);
            $views = (clone $base)->where('event_name', 'page_view');
            if ($action->page_path !== null) {
                $views->where('path', $action->page_path);
            }
            $clicks = (clone $base)->where('event_name', $action->event_name);
            if ($action->page_path !== null) {
                $clicks->where('path', $action->page_path);
            }
            $viewCount = (int) $views->count();
            $clickCount = (int) $clicks->count();
            $results[] = [
                'id' => (int) $action->getKey(),
                'name' => $action->name,
                'eventName' => $action->event_name,
                'pagePath' => $action->page_path,
                'views' => $viewCount,
                'clicks' => $clickCount,
                'ctr' => $viewCount > 0 ? round(($clickCount / $viewCount) * 100, 1) : 0.0,
            ];
        }

        return $results;
    }
}
