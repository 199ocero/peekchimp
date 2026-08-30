<?php

namespace App\Services\Mcp;

use App\Models\Funnel;
use App\Models\Project;
use App\Services\Analytics\FunnelAnalyticsService;
use App\Services\Websites\WebsiteSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ContentRecommendationService
{
    public function __construct(
        private readonly GrowthContextService $context,
        private readonly PageDiagnosticService $diagnostics,
        private readonly ContentOpportunityService $opportunities,
        private readonly WebsiteSnapshotService $snapshots,
        private readonly FunnelAnalyticsService $funnels,
    ) {}

    /** @return array<string, mixed> */
    public function brief(Project $project, ?string $path, ?string $primaryQuery, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opportunities = $this->opportunities->find($project, $from, $to, 30);
        $rankedOpportunities = $this->arrayItems($opportunities['ranked'] ?? []);
        if ($path === null && $primaryQuery !== null) {
            $match = collect($rankedOpportunities)->first(fn (array $item): bool => ($item['query'] ?? null) === $primaryQuery);
            $path = is_array($match) && is_string($match['path'] ?? null) ? $match['path'] : null;
        }
        $diagnostic = $path === null ? null : $this->diagnostics->diagnose($project, $path, $from, $to);
        $primaryQuery ??= data_get($diagnostic, 'search.queries.0.query');
        $searchQueries = $this->arrayItems(data_get($diagnostic, 'search.queries', []));
        $pageHeadings = $this->arrayItems(data_get($diagnostic, 'page.headings', []));
        $relatedQueries = collect($searchQueries)
            ->pluck('query')
            ->filter(fn (mixed $query): bool => is_string($query) && $query !== $primaryQuery)
            ->take(10)
            ->values()
            ->all();
        $content = Str::lower((string) data_get($diagnostic, 'page.mainContent', ''));
        $headings = collect($pageHeadings)->pluck('text')->filter()->values();
        $coverageGaps = collect([$primaryQuery, ...$relatedQueries])
            ->filter(fn (mixed $query): bool => is_string($query))
            ->filter(fn (string $query): bool => ! Str::contains($content, Str::lower($query)))
            ->values()
            ->all();
        $growthContext = $this->context->get($project);
        $conversionGoal = data_get($growthContext, 'context.primary_conversion_goals.0')
            ?? data_get($diagnostic, 'goals.0.name');
        $valueProposition = data_get($growthContext, 'context.value_proposition');
        $suggestedTitle = is_string($primaryQuery) && $primaryQuery !== ''
            ? Str::limit(Str::headline($primaryQuery).' | '.$project->name, 60, '')
            : data_get($diagnostic, 'page.title');
        $suggestedDescription = is_string($valueProposition) && $valueProposition !== ''
            ? Str::limit(Str::squish($valueProposition.(is_string($conversionGoal) ? ' '.$conversionGoal.'.' : '')), 155, '')
            : data_get($diagnostic, 'page.description');
        $outline = collect([$primaryQuery, ...$relatedQueries])
            ->filter(fn (mixed $query): bool => is_string($query) && $query !== '')
            ->unique()
            ->take(8)
            ->map(fn (string $query, int $index): array => [
                'level' => $index === 0 ? 'H1' : 'H2',
                'heading' => Str::headline($query),
                'purpose' => $index === 0
                    ? 'State the page promise and resolve the primary intent.'
                    : 'Directly answer this related query using verifiable business information.',
            ])->values()->all();
        $evidence = collect([
            data_get($diagnostic, 'page.evidenceRef'),
            data_get($diagnostic, 'analytics.evidenceRef'),
            data_get($diagnostic, 'search.evidenceRef'),
        ])->filter()->values()->all();

        return [
            'status' => $diagnostic === null ? 'new_content' : (string) ($diagnostic['status'] ?? 'partial'),
            'target' => ['path' => $path, 'primaryQuery' => $primaryQuery, 'relatedQueries' => $relatedQueries],
            'intentSignals' => collect($searchQueries)->take(10)->values()->all(),
            'currentPage' => $diagnostic === null ? null : [
                'title' => data_get($diagnostic, 'page.title'),
                'description' => data_get($diagnostic, 'page.description'),
                'headings' => $headings->all(),
                'ctaCandidates' => data_get($diagnostic, 'page.ctaCandidates', []),
            ],
            'coverageGaps' => $coverageGaps,
            'intent' => $this->intent($primaryQuery),
            'outline' => $outline,
            'suggestedTitle' => $suggestedTitle,
            'suggestedMetaDescription' => $suggestedDescription,
            'internalLinkCandidates' => $this->internalLinks($project, $primaryQuery),
            'conversionGoal' => $conversionGoal,
            'suggestedCta' => is_string($conversionGoal) ? ['text' => $conversionGoal, 'goal' => $conversionGoal] : null,
            'brandContext' => data_get($growthContext, 'context'),
            'evidence' => $evidence,
            'supportingEvidence' => $evidence,
            'generationInstructions' => [
                'Infer search intent from the supplied queries and clearly label the inference.',
                'Create an outline that resolves the coverage gaps without inventing unsupported claims.',
                'Provide a suggested title and meta description aligned with the primary query and brand context.',
                'Include the supplied internal-link candidates only where they are contextually relevant.',
                'End with a CTA aligned to the selected conversion goal.',
                'Cite the supplied evidence when explaining why each recommendation was made.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function improvements(Project $project, string $path, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $diagnostic = $this->diagnostics->diagnose($project, $path, $from, $to);
        $searchQueries = $this->arrayItems(data_get($diagnostic, 'search.queries', []));
        $pageHeadings = $this->arrayItems(data_get($diagnostic, 'page.headings', []));
        $candidates = collect();
        $primaryQuery = data_get($diagnostic, 'search.queries.0.query');
        $title = (string) data_get($diagnostic, 'page.title', '');
        if (is_string($primaryQuery) && ! Str::contains(Str::lower($title), Str::lower($primaryQuery))) {
            $candidates->push($this->candidate('rewrite', 'title', 'Align the title with the highest-impression query while preserving the page promise.', 'high', 'low', 'Organic CTR', [data_get($diagnostic, 'search.evidenceRef'), data_get($diagnostic, 'page.evidenceRef')]));
        }
        $content = Str::lower((string) data_get($diagnostic, 'page.mainContent', ''));
        foreach (collect($searchQueries)->take(5) as $query) {
            if (! Str::contains($content, Str::lower((string) $query['query']))) {
                $candidates->push($this->candidate('add', 'section', 'Add a section that directly answers the query “'.$query['query'].'”.', 'medium', 'medium', 'Clicks and engaged visits', [data_get($diagnostic, 'search.evidenceRef'), data_get($diagnostic, 'page.evidenceRef')]));
            }
        }
        if (data_get($diagnostic, 'analytics.entryVisits', 0) >= 10 && data_get($diagnostic, 'analytics.bounceRate', 0) >= 65) {
            $candidates->push($this->candidate('rewrite', 'opening', 'Make the promised answer and value proposition visible in the opening section.', 'high', 'medium', 'Bounce rate and average duration', [data_get($diagnostic, 'analytics.evidenceRef'), data_get($diagnostic, 'page.evidenceRef')]));
        }
        if (data_get($diagnostic, 'analytics.entryVisits', 0) >= 10 && data_get($diagnostic, 'analytics.conversions', 0) === 0) {
            $candidates->push($this->candidate('rewrite', 'cta', 'Clarify the primary next action and connect it to the configured conversion goal.', 'high', 'low', 'Conversion rate', [data_get($diagnostic, 'analytics.evidenceRef'), data_get($diagnostic, 'page.evidenceRef')]));
        }
        $h1Count = collect($pageHeadings)->where('level', 1)->count();
        if ($h1Count !== 1) {
            $candidates->push($this->candidate('reorganize', 'headings', 'Use one primary H1 and a logical H2/H3 section hierarchy.', 'medium', 'low', 'Engagement and search impressions', [data_get($diagnostic, 'page.evidenceRef')]));
        }

        return [
            'status' => $diagnostic['status'],
            'path' => $diagnostic['path'],
            'candidates' => $candidates->unique(fn (array $item): string => $item['action'].'|'.$item['target'])->take(10)->values()->all(),
            'generationInstructions' => 'Turn these ranked candidates into concrete copy or section edits, while preserving the distinction between measured facts and hypotheses.',
        ];
    }

    /** @return array<string, mixed> */
    public function experiments(Project $project, ?string $path, ?int $funnelId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opportunities = $this->opportunities->find($project, $from, $to, 10);
        $weakPage = collect($this->arrayItems($opportunities['ranked'] ?? []))->firstWhere('type', 'weak_landing_page');
        $path ??= is_array($weakPage) && is_string($weakPage['path'] ?? null) ? $weakPage['path'] : null;
        $diagnostic = $path === null ? null : $this->diagnostics->diagnose($project, $path, $from, $to);
        $experiments = collect();

        if ($diagnostic !== null) {
            $experiments->push($this->experiment(
                'cta_clarity',
                $path,
                'If the primary CTA states the value and next step more clearly, more entry visits will complete a configured goal.',
                'Replace competing or vague CTAs with one page-level primary action tied to the growth context.',
                'high',
                'low',
                'Conversion rate',
                data_get($diagnostic, 'analytics.conversionRate'),
                'Bounce rate',
                [data_get($diagnostic, 'analytics.evidenceRef'), data_get($diagnostic, 'page.evidenceRef')],
            ));
            if (data_get($diagnostic, 'analytics.bounceRate') !== null) {
                $experiments->push($this->experiment(
                    'message_match',
                    $path,
                    'If the opening message mirrors the dominant search intent, fewer organic entry visits will bounce.',
                    'Test an opening headline and summary that answer the highest-impression query immediately.',
                    'high',
                    'medium',
                    'Bounce rate',
                    data_get($diagnostic, 'analytics.bounceRate'),
                    'Conversion rate',
                    [data_get($diagnostic, 'search.evidenceRef'), data_get($diagnostic, 'analytics.evidenceRef')],
                ));
            }
        }

        $funnel = $funnelId === null
            ? $project->funnels()->where('is_active', true)->with('steps')->first()
            : $project->funnels()->where('is_active', true)->with('steps')->find($funnelId);
        if ($funnel instanceof Funnel) {
            $summary = $this->funnels->summary($funnel, $from, $to);
            $drop = collect($summary['steps'])->sortByDesc('dropOffPercentage')->first();
            if (is_array($drop)) {
                $experiments->push($this->experiment(
                    'funnel_drop_off',
                    $drop['name'],
                    'If the largest transition is simplified and its next action is clarified, more visits will reach the following funnel step.',
                    'Test one reduced-friction variant at the step with the largest measured drop-off.',
                    'high',
                    'medium',
                    'Step progression rate',
                    100 - (float) $drop['dropOffPercentage'],
                    'Overall funnel conversion rate',
                    ['analytics:funnel:'.$funnel->getKey().':'.$from->toDateString().':'.$to->toDateString()],
                ));
            }
        }

        return [
            'status' => $experiments->isEmpty() ? 'insufficient_data' : 'ok',
            'experiments' => $experiments->sortByDesc('priorityScore')->values()->all(),
            'measurementWindow' => [
                'baselineFrom' => $from->toDateString(),
                'baselineTo' => $to->toDateString(),
                'recheck' => 'Compare the same metric over the next complete period of equal length after implementation.',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function internalLinks(Project $project, ?string $query): array
    {
        $terms = collect(preg_split('/\W+/u', Str::lower((string) $query), -1, PREG_SPLIT_NO_EMPTY) ?: [])->filter(fn (string $term): bool => Str::length($term) >= 4);

        return $this->snapshots->latest($project)
            ->map(function ($page) use ($terms): array {
                $haystack = Str::lower(($page->title ?? '').' '.($page->main_content ?? ''));

                return [
                    'path' => $page->normalized_path,
                    'title' => $page->title,
                    'relevance' => $terms->filter(fn (string $term): bool => Str::contains($haystack, $term))->count(),
                    'evidenceRef' => 'snapshot:'.$page->normalized_path.'@'.$page->crawled_at->toIso8601String(),
                ];
            })
            ->sortByDesc('relevance')
            ->filter(fn (array $item): bool => $item['relevance'] > 0)
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string|null>  $evidence
     * @return array<string, mixed>
     */
    private function candidate(string $action, string $target, string $recommendation, string $impact, string $effort, string $metric, array $evidence): array
    {
        return [
            'action' => $action,
            'target' => $target,
            'recommendation' => $recommendation,
            'impact' => $impact,
            'effort' => $effort,
            'successMetric' => $metric,
            'evidence' => collect($evidence)->filter()->values()->all(),
        ];
    }

    /**
     * @param  array<int, string|null>  $evidence
     * @return array<string, mixed>
     */
    private function experiment(string $type, string $target, string $hypothesis, string $change, string $impact, string $effort, string $metric, mixed $baseline, string $guardrail, array $evidence): array
    {
        $impactScore = ['high' => 3, 'medium' => 2, 'low' => 1][$impact] ?? 1;
        $effortScore = ['low' => 3, 'medium' => 2, 'high' => 1][$effort] ?? 1;

        return [
            'type' => $type,
            'target' => $target,
            'hypothesis' => $hypothesis,
            'proposedChange' => $change,
            'impact' => $impact,
            'effort' => $effort,
            'priorityScore' => ($impactScore * 20) + ($effortScore * 10),
            'successMetric' => $metric,
            'baseline' => $baseline,
            'guardrail' => $guardrail,
            'evidence' => collect($evidence)->filter()->values()->all(),
        ];
    }

    /** @return array{type: string, confidence: string, rationale: string} */
    private function intent(?string $query): array
    {
        $query = Str::lower((string) $query);
        $type = match (true) {
            Str::contains($query, ['buy', 'pricing', 'price', 'demo', 'trial', 'hire', 'book']) => 'transactional',
            Str::contains($query, ['best', 'versus', ' vs ', 'alternative', 'review', 'compare']) => 'commercial_research',
            Str::contains($query, ['how', 'what', 'why', 'guide', 'tutorial']) => 'informational',
            default => 'mixed_or_unclear',
        };

        return [
            'type' => $type,
            'confidence' => $query === '' ? 'low' : 'medium',
            'rationale' => 'Inferred from query wording; validate against the current search results before publishing.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function arrayItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
    }
}
