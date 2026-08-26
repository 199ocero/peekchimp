<?php

namespace App\Actions\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Services\Analytics\BotDetector;
use App\Services\Analytics\EventNormalizer;
use App\Services\Analytics\GoalConversionService;
use App\Services\Analytics\SessionIdentifier;
use App\Services\Analytics\VisitorIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IngestEventsAction
{
    public function __construct(
        private readonly VisitorIdentifier $visitorIdentifier,
        private readonly SessionIdentifier $sessionIdentifier,
        private readonly EventNormalizer $eventNormalizer,
        private readonly BotDetector $botDetector,
        private readonly GoalConversionService $goalConversionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{accepted: int, filtered: int, duplicate: int, accepted_page_view: bool}
     */
    public function handle(Project $project, array $payload, Request $request): array
    {
        $now = CarbonImmutable::now();
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        $firstEvent = $events[0] ?? [];
        $platform = (string) ($firstEvent['platform'] ?? 'web');
        $botReason = $this->botDetector->reason($request, $platform);

        if ($botReason !== null) {
            return ['accepted' => 0, 'filtered' => count($events), 'duplicate' => 0, 'accepted_page_view' => false];
        }

        $visitorId = $this->visitorIdentifier->make($project, $request, $now);
        $rateKey = "analytics:ingestion:{$project->getKey()}:{$visitorId}";
        Cache::add($rateKey, 0, now()->addMinute());
        $rateCount = (int) Cache::increment($rateKey);

        if ($rateCount > 120) {
            return ['accepted' => 0, 'filtered' => count($events), 'duplicate' => 0, 'accepted_page_view' => false];
        }

        $accepted = 0;
        $acceptedPageView = false;
        $duplicate = 0;

        foreach ($events as $event) {
            $normalized = $this->eventNormalizer->normalize($event, $request, $now);
            $sessionId = $this->sessionIdentifier->make(
                $project,
                $visitorId,
                $normalized['client_session_id'],
                $normalized['event_id'],
                $normalized['occurred_at'],
            );

            $inserted = DB::table((new AnalyticsEvent)->getTable())->insertOrIgnore([
                'project_id' => $project->getKey(),
                'event_id' => $normalized['event_id'],
                'event_name' => $normalized['event_name'],
                'platform' => $normalized['platform'],
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
                'path' => $normalized['path'],
                'referrer_host' => $normalized['referrer_host'],
                'country' => $normalized['country'],
                'device' => $normalized['device'],
                'browser' => $normalized['browser'],
                'operating_system' => $normalized['operating_system'],
                'utm_source' => $normalized['utm_source'],
                'utm_medium' => $normalized['utm_medium'],
                'utm_campaign' => $normalized['utm_campaign'],
                'properties' => json_encode($normalized['properties'], JSON_THROW_ON_ERROR),
                'occurred_at' => $normalized['occurred_at']->toIso8601String(),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ]);

            if ($inserted === 0) {
                $duplicate++;

                continue;
            }

            $accepted++;
            $acceptedPageView = $acceptedPageView || $normalized['event_name'] === 'page_view';
            $this->updateSession(
                $project,
                $sessionId,
                $visitorId,
                $normalized,
            );
            $this->goalConversionService->record($project, $sessionId, $normalized);
        }

        return [
            'accepted' => $accepted,
            'filtered' => 0,
            'duplicate' => $duplicate,
            'accepted_page_view' => $acceptedPageView,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function updateSession(Project $project, string $sessionId, string $visitorId, array $event): void
    {
        $lock = Cache::lock("analytics:session:{$project->getKey()}:{$sessionId}", 3);

        try {
            $lock->block(1, function () use ($project, $sessionId, $visitorId, $event): void {
                $session = AnalyticsSession::query()->firstOrNew([
                    'project_id' => $project->getKey(),
                    'session_id' => $sessionId,
                ]);

                $occurredAt = $event['occurred_at'] instanceof CarbonImmutable
                    ? $event['occurred_at']
                    : CarbonImmutable::parse((string) $event['occurred_at']);
                $isPageView = $event['event_name'] === 'page_view';

                if (! $session->exists) {
                    $session->visitor_id = $visitorId;
                    $session->started_at = $occurredAt;
                    $session->last_seen_at = $occurredAt;
                    $session->entry_path = $isPageView ? $event['path'] : null;
                    $session->exit_path = $isPageView ? $event['path'] : null;
                    $session->referrer_host = $event['referrer_host'];
                    $session->country = $event['country'];
                    $session->device = $event['device'];
                    $session->browser = $event['browser'];
                    $session->operating_system = $event['operating_system'];
                    $session->utm_source = $event['utm_source'];
                    $session->utm_medium = $event['utm_medium'];
                    $session->utm_campaign = $event['utm_campaign'];
                }

                if ($session->country === null && $event['country'] !== null) {
                    $session->country = $event['country'];
                }

                foreach (['referrer_host', 'utm_source', 'utm_medium', 'utm_campaign'] as $attributionField) {
                    if ($session->{$attributionField} === null && $event[$attributionField] !== null) {
                        $session->{$attributionField} = $event[$attributionField];
                    }
                }

                if (in_array($session->browser, [null, 'Other'], true) && $event['browser'] !== 'Other') {
                    $session->browser = $event['browser'];
                }

                $lastSeenAt = $session->last_seen_at === null
                    ? null
                    : CarbonImmutable::parse((string) $session->last_seen_at);

                if ($lastSeenAt === null || $occurredAt->gt($lastSeenAt)) {
                    $session->last_seen_at = $occurredAt;
                    if ($isPageView) {
                        $session->exit_path = $event['path'];
                    }
                }

                if ($isPageView && $session->entry_path === null) {
                    $session->entry_path = $event['path'];
                }
                $session->pageviews = (int) $session->pageviews + ($isPageView ? 1 : 0);
                $session->custom_events = (int) $session->custom_events + ($isPageView ? 0 : 1);
                $startedAt = CarbonImmutable::parse((string) $session->started_at);
                $duration = $occurredAt->getTimestamp() - $startedAt->getTimestamp();
                $session->duration_seconds = max(0, $duration);
                $session->is_bounce = $session->pageviews <= 1 && $session->custom_events === 0;
                $session->save();
            });
        } catch (LockTimeoutException) {
            // A contended session will be repaired by its next event; the raw event is durable.
        }
    }
}
