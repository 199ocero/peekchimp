<?php

namespace App\Services\Mcp;

use App\Models\AnalyticsEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\WebsitePageSnapshot;
use App\Services\Analytics\AiProviderRegistry;
use App\Services\Websites\WebsiteSnapshotService;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Features;

class SetupGuidanceService
{
    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly WebsiteSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(Project $project, User $user): array
    {
        $project->loadMissing(['domains', 'searchConsoleConnection', 'aiVisibilityScans']);
        $workspaceOwner = $user->workspaceOwnerUser();
        $verifiedDomain = $project->domains->firstWhere('is_verified', true);
        $growthContext = $project->growthContext();
        $missingContext = collect($growthContext)
            ->filter(fn (mixed $value): bool => $value === '' || $value === [])
            ->keys()
            ->values()
            ->all();
        $crawlFreshness = $this->snapshots->freshness($project);
        $latestScan = $project->aiVisibilityScans->sortByDesc('created_at')->first();
        $searchConsole = $project->searchConsoleConnection;
        $aiSetting = $workspaceOwner->workspaceAiSetting;
        $providerValue = data_get($aiSetting, 'provider');
        $provider = is_string($providerValue) ? $providerValue : null;
        $hasRequiredAiKey = $provider !== null
            && (! $this->providers->requiresApiKey($provider) || filled(data_get($aiSetting, 'api_key')));
        $aiReady = $aiSetting !== null
            && $aiSetting->is_enabled
            && $provider !== null
            && $this->providers->isSupported($provider)
            && $hasRequiredAiKey;
        $isAdmin = $user->is_admin;

        $areas = [
            $this->area(
                key: 'website',
                title: 'Website and tracker',
                status: $verifiedDomain === null ? 'needs_setup' : 'ready',
                description: 'The tracker marks this website ready after it receives its first event from the verified domain.',
                current: [
                    'domainVerified' => $verifiedDomain !== null,
                    'lastEventAt' => $this->lastEventAt($project),
                ],
                canConfigureWithAi: false,
                settingsUrl: $verifiedDomain === null
                    ? route('websites.setup', $project)
                    : $this->websiteSettingsUrl($project, 'installation'),
                nextStep: $verifiedDomain === null
                    ? 'Install the tracker on the website, then load a page to verify the domain.'
                    : 'The website and tracker are receiving data.',
                priority: 1,
            ),
            $this->area(
                key: 'growth_context',
                title: 'Growth context',
                status: $missingContext === [] ? 'ready' : 'needs_setup',
                description: 'This tells the AI who you serve, what you offer, why it matters, and how your brand should sound.',
                current: ['missingFields' => $missingContext],
                canConfigureWithAi: true,
                settingsUrl: $this->websiteSettingsUrl($project, 'growth-context'),
                nextStep: $missingContext === []
                    ? 'Growth context is complete.'
                    : 'Confirm the missing business details before saving them.',
                priority: 3,
            ),
            $this->area(
                key: 'goals',
                title: 'Goals',
                status: $project->goals()->where('is_active', true)->exists() ? 'ready' : 'needs_setup',
                description: 'Goals count meaningful actions, such as a signup event or a visit to a thank-you page.',
                current: [
                    'activeGoalCount' => $project->goals()->where('is_active', true)->count(),
                    'observedEvents' => $this->observedEvents($project),
                    'crawledPaths' => $this->crawledPaths($project),
                ],
                canConfigureWithAi: true,
                settingsUrl: route('websites.goals.index', $project),
                nextStep: 'Start with one or two actions that represent real business value.',
                priority: 4,
            ),
            $this->area(
                key: 'website_crawl',
                title: 'Website crawl',
                status: $this->crawlStatus(data_get($latestScan, 'status'), data_get($latestScan, 'error'), $crawlFreshness['stale']),
                description: 'A crawl gives the AI safe page-level evidence for content, technical SEO, and conversion recommendations.',
                current: [
                    'scanStatus' => data_get($latestScan, 'status', 'not_started'),
                    'lastCrawledAt' => $crawlFreshness['last_crawled_at'],
                    'pageCount' => $crawlFreshness['page_count'],
                ],
                canConfigureWithAi: true,
                settingsUrl: $this->websiteSettingsUrl($project, 'website-crawl'),
                nextStep: $crawlFreshness['page_count'] > 0
                    ? 'Refresh the crawl when the website changes materially.'
                    : 'Queue a crawl after the website is verified.',
                priority: 2,
            ),
            $this->area(
                key: 'search_console',
                title: 'Google Search Console',
                status: $searchConsole === null ? 'optional' : 'ready',
                description: 'Search Console adds aggregate Google search clicks, impressions, rankings, and query opportunities.',
                current: [
                    'connected' => $searchConsole !== null,
                    'connectionStatus' => $searchConsole?->status,
                    'dataThrough' => $searchConsole?->data_through?->toDateString(),
                    'lastSyncedAt' => $searchConsole?->last_synced_at?->toIso8601String(),
                    'canManage' => $isAdmin,
                ],
                canConfigureWithAi: false,
                settingsUrl: $this->websiteSettingsUrl($project, 'search-console'),
                nextStep: $isAdmin
                    ? 'Connect your own Google account from Website settings. Never share Google credentials in chat.'
                    : 'Ask your workspace admin to connect Google Search Console.',
                priority: 5,
            ),
            $this->area(
                key: 'public_sharing',
                title: 'Public sharing',
                status: $project->hasPublicSharingEnabled() ? 'ready' : 'optional',
                description: 'Public sharing exposes selected aggregate dashboard sections through a revocable link.',
                current: [
                    'enabled' => $project->hasPublicSharingEnabled(),
                    'sections' => $project->publicDashboardSections(),
                ],
                canConfigureWithAi: false,
                settingsUrl: $this->websiteSettingsUrl($project, 'sharing'),
                nextStep: 'Review the selected sections and enable sharing manually only when the audience is intended.',
                priority: 8,
            ),
            $this->area(
                key: 'ai_settings',
                title: 'AI settings',
                status: $aiReady ? 'ready' : ($isAdmin ? 'needs_setup' : 'managed_by_admin'),
                description: 'Workspace AI powers Peekchimp Chat. Its provider and API key are kept private from MCP and chat responses.',
                current: [
                    'configured' => $aiSetting !== null && $hasRequiredAiKey,
                    'enabled' => (bool) data_get($aiSetting, 'is_enabled', false),
                    'ready' => $aiReady,
                    'canManage' => $isAdmin,
                ],
                canConfigureWithAi: false,
                settingsUrl: route('settings.ai.edit'),
                nextStep: $isAdmin
                    ? 'Add and test the provider credentials in AI settings. Never paste API keys into chat.'
                    : 'Ask your workspace admin to configure and enable the AI provider.',
                priority: 6,
            ),
            $this->area(
                key: 'members',
                title: 'Members',
                status: $isAdmin ? 'optional' : 'managed_by_admin',
                description: 'Workspace admins can invite teammates and manage their access from settings.',
                current: $isAdmin ? [
                    'memberCount' => $workspaceOwner->members()->where('is_admin', false)->count(),
                    'pendingInvitationCount' => $workspaceOwner->memberInvitations()->where('expires_at', '>', now())->count(),
                    'canManage' => true,
                ] : ['canManage' => false],
                canConfigureWithAi: false,
                settingsUrl: route('members.edit'),
                nextStep: $isAdmin
                    ? 'Invite members manually from workspace settings.'
                    : 'Your workspace admin manages members.',
                priority: 9,
            ),
            $this->area(
                key: 'profile',
                title: 'Profile',
                status: 'ready',
                description: 'Profile settings control your personal name and email address.',
                current: ['emailVerified' => $user->email_verified_at !== null],
                canConfigureWithAi: false,
                settingsUrl: route('profile.edit'),
                nextStep: 'Update your profile manually when your name or email changes.',
                priority: 10,
            ),
            $this->area(
                key: 'security',
                title: 'Security',
                status: $user->hasEnabledTwoFactorAuthentication() ? 'ready' : 'optional',
                description: 'Security settings let you update your password and enable two-factor authentication.',
                current: [
                    'twoFactorAvailable' => Features::canManageTwoFactorAuthentication(),
                    'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                ],
                canConfigureWithAi: false,
                settingsUrl: route('security.edit'),
                nextStep: 'Review password and two-factor authentication manually.',
                priority: 11,
            ),
            $this->area(
                key: 'mcp_connections',
                title: 'MCP connections',
                status: $this->activeMcpConnectionCount($user) > 0 ? 'ready' : 'optional',
                description: 'MCP connections let external AI clients read your workspace’s aggregate website data.',
                current: ['activeConnectionCount' => $this->activeMcpConnectionCount($user)],
                canConfigureWithAi: false,
                settingsUrl: route('settings.mcp.edit'),
                nextStep: 'Connect or revoke external clients manually from MCP settings.',
                priority: 12,
            ),
        ];

        $recommendedNextActions = collect($areas)
            ->filter(fn (array $area): bool => in_array($area['status'], ['needs_setup', 'attention'], true))
            ->sortBy('priority')
            ->take(5)
            ->map(fn (array $area): array => [
                'key' => $area['key'],
                'title' => $area['title'],
                'nextStep' => $area['nextStep'],
                'settingsUrl' => $area['settingsUrl'],
                'canConfigureWithAi' => $area['canConfigureWithAi'],
            ])
            ->values()
            ->all();

        return [
            'status' => $recommendedNextActions === [] ? 'ready' : 'needs_attention',
            'website' => [
                'id' => (int) $project->getKey(),
                'name' => (string) $project->name,
                'domain' => $project->domains->first()?->domain,
                'verifiedDomain' => $verifiedDomain?->domain,
            ],
            'summary' => [
                'readyAreaCount' => collect($areas)->where('status', 'ready')->count(),
                'actionableAreaCount' => count($recommendedNextActions),
            ],
            'areas' => $areas,
            'recommendedNextActions' => $recommendedNextActions,
        ];
    }

    private function lastEventAt(Project $project): ?string
    {
        $occurredAt = AnalyticsEvent::query()
            ->whereBelongsTo($project)
            ->latest('occurred_at')
            ->value('occurred_at');

        return $occurredAt === null ? null : Carbon::parse($occurredAt)->toIso8601String();
    }

    /** @return array<int, array{name: string, occurrences: int}> */
    private function observedEvents(Project $project): array
    {
        return AnalyticsEvent::query()
            ->whereBelongsTo($project)
            ->where('event_name', '!=', 'page_view')
            ->where('occurred_at', '>=', now()->subDays(30))
            ->selectRaw('event_name, COUNT(*) AS occurrences')
            ->groupBy('event_name')
            ->orderByDesc('occurrences')
            ->orderBy('event_name')
            ->limit(20)
            ->get()
            ->map(fn (AnalyticsEvent $event): array => [
                'name' => (string) $event->event_name,
                'occurrences' => (int) $event->getAttribute('occurrences'),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function crawledPaths(Project $project): array
    {
        return WebsitePageSnapshot::query()
            ->whereBelongsTo($project)
            ->whereNotNull('normalized_path')
            ->distinct()
            ->orderBy('normalized_path')
            ->limit(20)
            ->pluck('normalized_path')
            ->map(fn (mixed $path): string => (string) $path)
            ->all();
    }

    private function crawlStatus(?string $status, ?string $error, bool $isStale): string
    {
        if (in_array($status, ['queued', 'running'], true)) {
            return 'in_progress';
        }

        if (filled($error)) {
            return 'attention';
        }

        return $status === null || $isStale ? 'needs_setup' : 'ready';
    }

    private function activeMcpConnectionCount(User $user): int
    {
        return $user->tokens()
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->get()
            ->filter(fn ($token): bool => $token->can('mcp:use'))
            ->pluck('client_id')
            ->unique()
            ->count();
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function area(
        string $key,
        string $title,
        string $status,
        string $description,
        array $current,
        bool $canConfigureWithAi,
        string $settingsUrl,
        string $nextStep,
        int $priority,
    ): array {
        return compact('key', 'title', 'status', 'description', 'current', 'canConfigureWithAi', 'settingsUrl', 'nextStep', 'priority');
    }

    private function websiteSettingsUrl(Project $project, string $section): string
    {
        return route('websites.settings.edit', $project).'#'.$section;
    }
}
