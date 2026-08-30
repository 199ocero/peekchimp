<?php

use App\Jobs\BackfillGoalConversions;
use App\Jobs\RunAiVisibilityScan;
use App\Mcp\Prompts\AnalyzeWebsitePerformance;
use App\Mcp\Prompts\PlanWebsiteGrowth;
use App\Mcp\Resources\AnalyticsMethodologyResource;
use App\Mcp\Servers\PeekchimpServer;
use App\Mcp\Tools\BuildContentBrief;
use App\Mcp\Tools\CreateGoal;
use App\Mcp\Tools\FindContentOpportunities;
use App\Mcp\Tools\GetAnalyticsOverview;
use App\Mcp\Tools\GetOrganicSearchOpportunities;
use App\Mcp\Tools\GetPageDiagnostic;
use App\Mcp\Tools\GetSetupGuide;
use App\Mcp\Tools\GetTechnicalSeoIssues;
use App\Mcp\Tools\GetWebsiteContext;
use App\Mcp\Tools\ListWebsites;
use App\Mcp\Tools\RecommendContentImprovements;
use App\Mcp\Tools\RecommendConversionExperiments;
use App\Mcp\Tools\SaveGrowthContext;
use App\Mcp\Tools\StartWebsiteCrawl;
use App\Models\AiVisibilityScan;
use App\Models\AnalyticsEvent;
use App\Models\SearchConsoleConnection;
use App\Models\SearchConsoleMetric;
use App\Models\User;
use App\Models\WebsitePageSnapshot;
use App\Models\WorkspaceAiSetting;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

test('mcp publishes oauth discovery metadata and protects the streamable http endpoint', function () {
    $this->get('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('scopes_supported.0', 'mcp:use')
        ->assertJsonPath('code_challenge_methods_supported.0', 'S256');

    $this->get('/mcp')
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');

    $this->postJson('/mcp', [])->assertUnauthorized();
});

test('mcp dynamic client registration grants only the mcp scope', function () {
    $response = $this->postJson('/oauth/register', [
        'client_name' => 'ChatGPT',
        'redirect_uris' => ['https://chatgpt.com/callback'],
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('scope', 'mcp:use')
        ->assertJsonPath('token_endpoint_auth_method', 'none');

    $scopes = Client::query()->find($response->json('client_id'))?->scopes ?? [];

    expect($response->json('client_id'))->toBeString()
        ->and($scopes)->toContain('mcp:use');
});

test('the mcp endpoint requires the mcp use scope', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $request = [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0'],
        ],
    ];

    Passport::actingAs($user, []);
    $this->postJson('/mcp', $request)->assertForbidden();

    Passport::actingAs($user, ['mcp:use']);
    $this->postJson('/mcp', $request)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Peekchimp Analytics');
});

test('authenticated users can discover only their workspace websites', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $otherUser = User::factory()->withVerifiedWebsite()->create();

    $response = PeekchimpServer::actingAs($user)->tool(ListWebsites::class);

    $response
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->has('websites', 1)
            ->where('websites.0.id', $project->getKey())
            ->where('websites.0.name', $project->name)
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(GetAnalyticsOverview::class, ['project_id' => $otherUser->projects()->sole()->getKey()])
        ->assertHasErrors(['not available']);
});

test('analytics tools return structured aggregate data without legacy insights', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    PeekchimpServer::actingAs($user)
        ->tool(GetAnalyticsOverview::class, [
            'project_id' => $project->getKey(),
            'range' => '7d',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->where('range.key', '7d')
            ->has('data.metrics')
            ->has('data.timeseries')
            ->has('data.aiTraffic')
            ->missing('data.insights')
            ->etc(),
        );
});

test('report ranges are bounded and reject invalid custom dates', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    PeekchimpServer::actingAs($user)
        ->tool(GetAnalyticsOverview::class, [
            'project_id' => $project->getKey(),
            'range' => 'custom',
            'from' => '2026-02-30',
            'to' => '2026-03-01',
        ])
        ->assertHasErrors(['invalid']);
});

test('search tools explain when Search Console is not connected', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();

    PeekchimpServer::actingAs($user)
        ->tool(GetOrganicSearchOpportunities::class, ['project_id' => $project->getKey()])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'not_connected')
            ->where('data.opportunities', [])
            ->etc(),
        );
});

test('mcp resources and prompts provide interpretation guidance', function () {
    $user = User::factory()->withVerifiedWebsite()->create();

    PeekchimpServer::actingAs($user)
        ->resource(AnalyticsMethodologyResource::class)
        ->assertOk()
        ->assertSee(['Tracked visit rate', 'Paid Google sessions are excluded']);

    PeekchimpServer::actingAs($user)
        ->prompt(AnalyzeWebsitePerformance::class, ['project_id' => 1, 'range' => '30d'])
        ->assertOk()
        ->assertSee(['get-analytics-overview', 'aggregate data']);

    PeekchimpServer::actingAs($user)
        ->prompt(PlanWebsiteGrowth::class, ['project_id' => 1, 'focus' => 'content'])
        ->assertOk()
        ->assertSee(['get-website-context', 'read-only mode', 'measurement plan']);
});

test('growth consultant tools return read-only evidence packets from the same tenant', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $project->update([
        'settings' => [
            'growth_context' => [
                'audience' => 'Privacy-conscious software teams.',
                'products_services' => 'Website analytics.',
                'value_proposition' => 'Clear actions from aggregate data.',
                'brand_voice' => 'Practical and direct.',
                'primary_conversion_goals' => ['Start a trial'],
            ],
        ],
    ]);
    $scan = AiVisibilityScan::factory()->for($project)->create();
    WebsitePageSnapshot::factory()
        ->for($project)
        ->for($scan, 'scan')
        ->create([
            'url' => 'https://example.test/pricing',
            'url_hash' => hash('sha256', 'https://example.test/pricing'),
            'normalized_path' => '/pricing',
            'title' => 'Simple pricing',
            'meta_description' => null,
            'canonical_url' => null,
            'headings' => [['level' => 1, 'text' => 'Pricing']],
            'cta_candidates' => [['type' => 'link', 'text' => 'Try it', 'url' => '/signup']],
            'main_content' => 'Simple pricing for privacy-conscious software teams. Try it today.',
        ]);

    $input = ['project_id' => $project->getKey(), 'range' => '30d'];

    PeekchimpServer::actingAs($user)
        ->tool(GetWebsiteContext::class, ['project_id' => $project->getKey()])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->where('data.context.audience', 'Privacy-conscious software teams.')
            ->where('data.freshness.page_count', 1)
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(GetPageDiagnostic::class, [...$input, 'path' => '/pricing'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->where('data.path', '/pricing')
            ->where('data.page.title', 'Simple pricing')
            ->has('data.analytics')
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(FindContentOpportunities::class, $input)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'not_connected')
            ->has('data.ranked')
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(BuildContentBrief::class, [...$input, 'path' => '/pricing'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->where('data.target.path', '/pricing')
            ->where('data.conversionGoal', 'Start a trial')
            ->has('data.intent')
            ->has('data.outline')
            ->has('data.suggestedTitle')
            ->has('data.suggestedMetaDescription')
            ->has('data.internalLinkCandidates')
            ->has('data.supportingEvidence')
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(RecommendContentImprovements::class, [...$input, 'path' => '/pricing'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->has('data.candidates')
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(RecommendConversionExperiments::class, [...$input, 'path' => '/pricing'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->has('data.experiments', 1)
            ->has('data.measurementWindow')
            ->etc(),
        );

    PeekchimpServer::actingAs($user)
        ->tool(GetTechnicalSeoIssues::class, ['project_id' => $project->getKey(), 'path' => '/pricing'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'issues_found')
            ->has('data.issues')
            ->etc(),
        );
});

test('growth tools cannot read another workspace website or snapshot', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $otherUser = User::factory()->withVerifiedWebsite()->create();
    $otherProject = $otherUser->projects()->sole();

    PeekchimpServer::actingAs($user)
        ->tool(GetPageDiagnostic::class, [
            'project_id' => $otherProject->getKey(),
            'path' => '/',
        ])
        ->assertHasErrors(['not available']);
});

test('content opportunities rank low ctr queries and detect cannibalization', function () {
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $user->getKey(),
        'data_through' => today(),
        'last_synced_at' => now(),
    ]);
    SearchConsoleMetric::factory()->for($project)->create([
        'report_date' => today(),
        'dimension_type' => 'property',
        'dimension_value' => '',
        'dimension_hash' => sha1(''),
        'clicks' => 3,
        'impressions' => 200,
        'position' => 8,
    ]);
    foreach ([
        ['/guides/analytics', 120, 2, 8.0],
        ['/features/analytics', 80, 1, 11.0],
    ] as [$path, $impressions, $clicks, $position]) {
        SearchConsoleMetric::factory()->for($project)->create([
            'report_date' => today(),
            'dimension_type' => 'query_page',
            'dimension_value' => 'privacy friendly analytics',
            'dimension_hash' => sha1("privacy friendly analytics\0{$path}"),
            'normalized_path' => $path,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'position' => $position,
        ]);
    }

    PeekchimpServer::actingAs($user)
        ->tool(FindContentOpportunities::class, [
            'project_id' => $project->getKey(),
            'range' => '30d',
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ok')
            ->where('data.highImpressionQueries.0.type', 'high_impression_query')
            ->where('data.highImpressionQueries.0.path', '/guides/analytics')
            ->where('data.cannibalization.0.query', 'privacy friendly analytics')
            ->has('data.cannibalization.0.metrics.pages', 2)
            ->etc(),
        );
});

test('setup guide returns safe, role-aware readiness and never exposes secrets', function () {
    $user = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $project = $user->projects()->sole();
    $project->update([
        'public_share_token' => 'public-share-secret',
        'public_share_token_hash' => hash('sha256', 'public-share-secret'),
        'public_share_enabled_at' => now(),
    ]);
    WorkspaceAiSetting::factory()->for($user, 'workspaceOwner')->create([
        'api_key' => 'workspace-ai-secret',
        'is_enabled' => true,
        'status' => 'configured',
    ]);
    SearchConsoleConnection::factory()->for($project)->create([
        'connected_by_user_id' => $user->getKey(),
        'access_token' => 'search-console-access-secret',
        'refresh_token' => 'search-console-refresh-secret',
        'status' => 'connected',
    ]);
    AnalyticsEvent::factory()->for($project)->create([
        'event_name' => 'demo_requested',
        'occurred_at' => now(),
    ]);

    PeekchimpServer::actingAs($user)
        ->tool(GetSetupGuide::class, ['project_id' => $project->getKey()])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'needs_attention')
            ->where('data.website.id', $project->getKey())
            ->where('data.areas.0.key', 'website')
            ->where('data.areas.0.status', 'ready')
            ->where('data.areas.2.key', 'goals')
            ->where('data.areas.2.current.observedEvents.0.name', 'demo_requested')
            ->where('data.areas.4.current.connected', true)
            ->where('data.areas.6.current.ready', true)
            ->etc(),
        )
        ->assertDontSee([
            'workspace-ai-secret',
            'search-console-access-secret',
            'search-console-refresh-secret',
            'public-share-secret',
        ]);
});

test('setup guide does not reveal member management details to workspace members', function () {
    $owner = User::factory()->withVerifiedWebsite()->create(['is_admin' => true]);
    $member = User::factory()->create([
        'workspace_owner_id' => $owner->getKey(),
        'is_admin' => false,
    ]);
    $project = $owner->projects()->sole();

    PeekchimpServer::actingAs($member)
        ->tool(GetSetupGuide::class, ['project_id' => $project->getKey()])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('data.areas.7.key', 'members')
            ->where('data.areas.7.status', 'managed_by_admin')
            ->where('data.areas.7.current.canManage', false)
            ->missing('data.areas.7.current.memberCount')
            ->missing('data.areas.7.current.pendingInvitationCount')
            ->etc(),
        );
});

test('setup tools save growth context, create idempotent goals, and queue a verified crawl', function () {
    Queue::fake();
    $user = User::factory()->withVerifiedWebsite()->create();
    $project = $user->projects()->sole();
    $project->update([
        'settings' => ['public_dashboard' => ['sections' => ['metrics']]],
    ]);

    PeekchimpServer::actingAs($user)
        ->tool(SaveGrowthContext::class, [
            'project_id' => $project->getKey(),
            'audience' => 'Small product teams',
            'primary_conversion_goals' => ['Book a demo'],
        ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'updated')
            ->where('data.context.audience', 'Small product teams')
            ->where('data.context.primary_conversion_goals.0', 'Book a demo')
            ->etc(),
        );

    expect($project->refresh()->publicDashboardSections())->toBe(['metrics']);

    $goalInput = [
        'project_id' => $project->getKey(),
        'name' => 'Book a demo',
        'type' => 'event',
        'event_name' => 'demo_requested',
    ];

    PeekchimpServer::actingAs($user)
        ->tool(CreateGoal::class, $goalInput)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'created')
            ->where('data.goal.eventName', 'demo_requested')
            ->etc(),
        );
    Queue::assertPushed(BackfillGoalConversions::class);

    PeekchimpServer::actingAs($user)
        ->tool(CreateGoal::class, $goalInput)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'already_exists')
            ->etc(),
        );
    expect($project->goals()->count())->toBe(1);

    PeekchimpServer::actingAs($user)
        ->tool(StartWebsiteCrawl::class, ['project_id' => $project->getKey()])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'queued')
            ->etc(),
        );
    Queue::assertPushed(RunAiVisibilityScan::class);

    PeekchimpServer::actingAs($user)
        ->tool(StartWebsiteCrawl::class, ['project_id' => $project->getKey()])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'already_in_progress')
            ->etc(),
        );
});
