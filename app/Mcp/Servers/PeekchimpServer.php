<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\AnalyzeWebsitePerformance;
use App\Mcp\Prompts\FindSeoOpportunities;
use App\Mcp\Prompts\PlanWebsiteGrowth;
use App\Mcp\Resources\AnalyticsMethodologyResource;
use App\Mcp\Tools\BuildContentBrief;
use App\Mcp\Tools\CheckBehavioralSignals;
use App\Mcp\Tools\CreateGoal;
use App\Mcp\Tools\DeleteGoal;
use App\Mcp\Tools\FindContentOpportunities;
use App\Mcp\Tools\FindFriction;
use App\Mcp\Tools\GetAnalyticsOverview;
use App\Mcp\Tools\GetConversionPerformance;
use App\Mcp\Tools\GetOrganicSearchOpportunities;
use App\Mcp\Tools\GetPageDiagnostic;
use App\Mcp\Tools\GetSearchPerformance;
use App\Mcp\Tools\GetSetupGuide;
use App\Mcp\Tools\GetTechnicalSeoIssues;
use App\Mcp\Tools\GetWebsiteContext;
use App\Mcp\Tools\InvestigateChange;
use App\Mcp\Tools\InvestigateFunnel;
use App\Mcp\Tools\ListWebsites;
use App\Mcp\Tools\RecommendContentImprovements;
use App\Mcp\Tools\RecommendConversionExperiments;
use App\Mcp\Tools\SaveGrowthContext;
use App\Mcp\Tools\StartWebsiteCrawl;
use App\Mcp\Tools\UpdateGoal;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Peekchimp Analytics')]
#[Version('1.3.0')]
#[Instructions('Peekchimp provides website setup guidance and aggregate growth evidence from analytics, Google Search Console, configured business context, and sanitized public-page snapshots. For setup or configuration questions, start with list-websites and get-setup-guide. For verifying behavioral signal collection, start with check-behavioral-signals. For broad questions about what to fix, start with find-friction. Use investigate-change for before-and-after comparisons, investigate-funnel for a specific funnel drop-off, and get-page-diagnostic for a page-level evidence packet. Only save growth context, create, update, or delete a goal, or start a website crawl after the user explicitly approves the exact change. Never ask for, accept, expose, or change credentials, Google OAuth tokens, public-sharing settings, member access, profile information, passwords, security settings, or MCP connections. Distinguish measured facts from hypotheses, cite returned evidence references, and never infer individual visitors or personal information. Recommendations are drafts only and never publish website changes.')]
class PeekchimpServer extends Server
{
    protected array $tools = [
        ListWebsites::class,
        GetAnalyticsOverview::class,
        GetSearchPerformance::class,
        GetOrganicSearchOpportunities::class,
        GetConversionPerformance::class,
        GetSetupGuide::class,
        GetWebsiteContext::class,
        SaveGrowthContext::class,
        CreateGoal::class,
        UpdateGoal::class,
        DeleteGoal::class,
        StartWebsiteCrawl::class,
        CheckBehavioralSignals::class,
        GetPageDiagnostic::class,
        FindContentOpportunities::class,
        BuildContentBrief::class,
        RecommendContentImprovements::class,
        RecommendConversionExperiments::class,
        GetTechnicalSeoIssues::class,
        InvestigateFunnel::class,
        InvestigateChange::class,
        FindFriction::class,
    ];

    protected array $resources = [
        AnalyticsMethodologyResource::class,
    ];

    protected array $prompts = [
        AnalyzeWebsitePerformance::class,
        FindSeoOpportunities::class,
        PlanWebsiteGrowth::class,
    ];
}
