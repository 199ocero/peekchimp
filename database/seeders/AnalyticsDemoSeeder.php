<?php

namespace Database\Seeders;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\Project;
use App\Models\ProjectDomain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const REQUESTED_DOMAIN_ID = 2;

    private const FALLBACK_PROJECT_ID = 2;

    private const SESSION_PREFIX = 'demo-session-';

    /**
     * Run the analytics demo seeder.
     */
    public function run(): void
    {
        $project = $this->targetProject();

        DB::transaction(function () use ($project): void {
            $this->removeExistingDemoData($project);

            $now = CarbonImmutable::now($project->timezone);
            $eventSequence = 1;

            for ($dayOffset = 29; $dayOffset >= 0; $dayOffset--) {
                $day = $now->startOfDay()->subDays($dayOffset);

                for ($sessionNumber = 1; $sessionNumber <= $this->sessionsForDay($dayOffset); $sessionNumber++) {
                    $this->createDemoSession(
                        $project,
                        $day,
                        $now,
                        $dayOffset,
                        $sessionNumber,
                        $eventSequence,
                    );
                }
            }
        });
    }

    private function targetProject(): Project
    {
        $domain = ProjectDomain::query()
            ->with('project')
            ->find(self::REQUESTED_DOMAIN_ID);

        if ($domain?->project instanceof Project) {
            return $domain->project;
        }

        return Project::query()->findOrFail(self::FALLBACK_PROJECT_ID);
    }

    private function removeExistingDemoData(Project $project): void
    {
        $sessionPattern = self::SESSION_PREFIX.$project->getKey().'-%';

        AnalyticsEvent::query()
            ->whereBelongsTo($project)
            ->where('session_id', 'like', $sessionPattern)
            ->delete();

        AnalyticsSession::query()
            ->whereBelongsTo($project)
            ->where('session_id', 'like', $sessionPattern)
            ->delete();
    }

    private function sessionsForDay(int $dayOffset): int
    {
        return match (true) {
            $dayOffset === 0 => 24,
            $dayOffset === 1 => 20,
            $dayOffset <= 6 => 12,
            $dayOffset <= 13 => 8,
            default => 5,
        };
    }

    private function createDemoSession(
        Project $project,
        CarbonImmutable $day,
        CarbonImmutable $now,
        int $dayOffset,
        int $sessionNumber,
        int &$eventSequence,
    ): void {
        $profileIndex = $dayOffset * 13 + $sessionNumber * 7;
        $paths = [
            '/',
            '/features',
            '/pricing',
            '/blog/analytics-that-makes-sense',
            '/docs/getting-started',
            '/about',
            '/contact',
            '/signup',
        ];
        $referrers = [
            null,
            'www.google.com',
            'www.bing.com',
            'twitter.com',
            'linkedin.com',
            'news.ycombinator.com',
            'chatgpt.com',
            'claude.ai',
            'perplexity.ai',
            'gemini.google.com',
            'copilot.microsoft.com',
        ];
        $campaigns = [
            null,
            'spring-launch',
            'product-update',
            'newsletter-august',
            'creator-collab',
            'paid-search',
        ];
        $countries = ['US', 'GB', 'CA', 'AU', 'PH', 'DE', 'SG', 'IN', 'JP', 'BR'];
        $devices = ['desktop', 'mobile', 'tablet'];
        $browsers = ['Chrome', 'Safari', 'Firefox', 'Edge', 'Opera'];
        $operatingSystems = ['macOS', 'Windows', 'iOS', 'Android', 'Linux'];
        $platforms = ['web', 'web', 'web', 'web', 'ios', 'android', 'react-native', 'flutter'];
        $customEventNames = [
            'signup',
            'cta_click',
            'newsletter_subscribe',
            'purchase',
            'video_play',
            'file_download',
            'search',
            'checkout_started',
        ];

        $referrer = $referrers[$profileIndex % count($referrers)];
        $campaign = $campaigns[$profileIndex % count($campaigns)];
        $utmSource = $this->utmSource($referrer, $campaign);
        $utmMedium = $this->utmMedium($referrer, $campaign);
        $pageviews = 1 + (($sessionNumber + $dayOffset) % 4);
        $customEvents = match (($sessionNumber + $dayOffset) % 6) {
            0, 1 => 2,
            2, 3 => 1,
            default => 0,
        };
        $durationSeconds = $pageviews === 1 && $customEvents === 0
            ? 0
            : 90 + (($sessionNumber * 97 + $dayOffset * 31) % 1500);
        $startedAt = $this->startedAt($day, $now, $dayOffset, $sessionNumber);
        $lastSeenAt = $startedAt->addSeconds($durationSeconds);

        if ($dayOffset === 0) {
            $latestSeenAt = $now->subMinutes(3);

            if ($lastSeenAt->gt($latestSeenAt)) {
                $lastSeenAt = $latestSeenAt;
                $durationSeconds = max(0, $startedAt->diffInSeconds($lastSeenAt));
            }
        }

        $sessionId = sprintf(
            '%s%s-%02d-%03d',
            self::SESSION_PREFIX,
            $project->getKey(),
            $dayOffset,
            $sessionNumber,
        );
        $visitorId = sprintf(
            'demo-visitor-%s-%04d',
            $project->getKey(),
            (($dayOffset * 19 + $sessionNumber * 7) % 120) + 1,
        );
        $device = $devices[$profileIndex % count($devices)];
        $browser = $browsers[$profileIndex % count($browsers)];
        $operatingSystem = $operatingSystems[$profileIndex % count($operatingSystems)];
        $country = $countries[$profileIndex % count($countries)];
        $platform = $platforms[$profileIndex % count($platforms)];
        $entryPath = $paths[$profileIndex % count($paths)];
        $exitPath = $paths[($profileIndex + $pageviews - 1) % count($paths)];

        AnalyticsSession::query()->create([
            'project_id' => $project->getKey(),
            'session_id' => $sessionId,
            'visitor_id' => $visitorId,
            'started_at' => $startedAt->utc()->toDateTimeString(),
            'last_seen_at' => $lastSeenAt->utc()->toDateTimeString(),
            'pageviews' => $pageviews,
            'custom_events' => $customEvents,
            'duration_seconds' => $durationSeconds,
            'is_bounce' => $pageviews <= 1 && $customEvents === 0,
            'entry_path' => $entryPath,
            'exit_path' => $exitPath,
            'referrer_host' => $referrer,
            'country' => $country,
            'device' => $device,
            'browser' => $browser,
            'operating_system' => $operatingSystem,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $campaign,
        ]);

        $eventCount = $pageviews + $customEvents;
        $eventStep = $eventCount > 1 ? intdiv($durationSeconds, $eventCount - 1) : 0;
        $eventIndex = 0;

        for ($pageviewNumber = 0; $pageviewNumber < $pageviews; $pageviewNumber++) {
            $path = $paths[($profileIndex + $pageviewNumber) % count($paths)];
            $occurredAt = $startedAt->addSeconds(min($durationSeconds, $eventIndex * $eventStep));

            $this->createEvent(
                $project,
                $eventSequence++,
                'page_view',
                $platform,
                $visitorId,
                $sessionId,
                $path,
                $referrer,
                $country,
                $device,
                $browser,
                $operatingSystem,
                $utmSource,
                $utmMedium,
                $campaign,
                [
                    'demo_data' => true,
                    'page_title' => $path === '/' ? 'Home' : Str::headline(basename($path)),
                    'language' => $profileIndex % 3 === 0 ? 'en-US' : 'en-GB',
                    'viewport_width' => 1280 + (($profileIndex * 17) % 480),
                ],
                $occurredAt,
            );

            $eventIndex++;
        }

        for ($customEventNumber = 0; $customEventNumber < $customEvents; $customEventNumber++) {
            $path = $paths[($profileIndex + $pageviews + $customEventNumber) % count($paths)];
            $eventName = $customEventNames[($profileIndex + $customEventNumber) % count($customEventNames)];
            $occurredAt = $startedAt->addSeconds(min($durationSeconds, $eventIndex * $eventStep));

            $this->createEvent(
                $project,
                $eventSequence++,
                $eventName,
                $platform,
                $visitorId,
                $sessionId,
                $path,
                $referrer,
                $country,
                $device,
                $browser,
                $operatingSystem,
                $utmSource,
                $utmMedium,
                $campaign,
                [
                    'demo_data' => true,
                    'value' => 1 + (($profileIndex + $customEventNumber) % 5),
                    'plan' => ['free', 'pro', 'team'][$profileIndex % 3],
                    'source' => $utmSource ?? 'direct',
                ],
                $occurredAt,
            );

            $eventIndex++;
        }
    }

    private function startedAt(
        CarbonImmutable $day,
        CarbonImmutable $now,
        int $dayOffset,
        int $sessionNumber,
    ): CarbonImmutable {
        $hour = 6 + (($sessionNumber * 3 + $dayOffset) % 12);
        $minute = ($sessionNumber * 17 + $dayOffset * 11) % 60;
        $startedAt = $day->startOfDay()->addHours($hour)->addMinutes($minute);

        if ($dayOffset !== 0) {
            return $startedAt;
        }

        $latestStart = $now->subMinutes(15);

        if ($startedAt->gt($latestStart)) {
            $startedAt = $latestStart->subMinutes(($sessionNumber * 3) % 15);
        }

        return $startedAt->lt($day->startOfDay()->addMinute())
            ? $day->startOfDay()->addMinute()
            : $startedAt;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function createEvent(
        Project $project,
        int $eventSequence,
        string $eventName,
        string $platform,
        string $visitorId,
        string $sessionId,
        string $path,
        ?string $referrer,
        string $country,
        string $device,
        string $browser,
        string $operatingSystem,
        ?string $utmSource,
        ?string $utmMedium,
        ?string $campaign,
        array $properties,
        CarbonImmutable $occurredAt,
    ): void {
        AnalyticsEvent::query()->create([
            'project_id' => $project->getKey(),
            'event_id' => sprintf('00000000-0000-4000-8000-%012d', $eventSequence),
            'event_name' => $eventName,
            'platform' => $platform,
            'visitor_id' => $visitorId,
            'session_id' => $sessionId,
            'path' => $path,
            'referrer_host' => $referrer,
            'country' => $country,
            'device' => $device,
            'browser' => $browser,
            'operating_system' => $operatingSystem,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $campaign,
            'properties' => $properties,
            'occurred_at' => $occurredAt->utc()->toDateTimeString(),
        ]);
    }

    private function utmSource(?string $referrer, ?string $campaign): ?string
    {
        return match ($referrer) {
            'chatgpt.com' => 'chatgpt',
            'claude.ai' => 'claude',
            'perplexity.ai' => 'perplexity',
            'gemini.google.com' => 'gemini',
            'copilot.microsoft.com' => 'copilot',
            'www.google.com' => 'google',
            'www.bing.com' => 'bing',
            'twitter.com' => 'twitter',
            'linkedin.com' => 'linkedin',
            'news.ycombinator.com' => 'hackernews',
            default => match ($campaign) {
                'newsletter-august' => 'newsletter',
                'paid-search' => 'google',
                'creator-collab' => 'linkedin',
                default => null,
            },
        };
    }

    private function utmMedium(?string $referrer, ?string $campaign): ?string
    {
        return match ($campaign) {
            'newsletter-august' => 'email',
            'paid-search' => 'cpc',
            'creator-collab' => 'partner',
            default => match ($referrer) {
                'www.google.com', 'www.bing.com' => 'organic',
                'twitter.com', 'linkedin.com', 'news.ycombinator.com' => 'social',
                'chatgpt.com', 'claude.ai', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com' => 'referral',
                default => null,
            },
        };
    }
}
