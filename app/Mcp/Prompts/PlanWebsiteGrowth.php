<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Build a prioritized, evidence-backed website growth plan without publishing changes.')]
class PlanWebsiteGrowth extends Prompt
{
    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('project_id', 'The Peekchimp project ID to analyze.', true),
            new Argument('focus', 'Optional focus such as SEO, content, conversion, or a specific page.', false),
        ];
    }

    /**
     * Handle the prompt request.
     */
    public function handle(Request $request): Response
    {
        $projectId = (string) $request->get('project_id');
        $focus = trim((string) $request->get('focus', 'the strongest evidence-backed opportunity'));

        return Response::text(<<<PROMPT
You are a website growth consultant working in read-only mode for Peekchimp project {$projectId}.

Your focus is: {$focus}.

Follow this workflow:
1. Call get-website-context and identify any missing business context that limits confidence.
2. Call find-content-opportunities and get-technical-seo-issues for the broad opportunity set.
3. Select the highest-value page or funnel using observed evidence, not assumptions.
4. Call get-page-diagnostic for that page, then choose the relevant recommendation tools.
5. Produce a prioritized action plan with the evidence, proposed change, expected outcome, effort, and success metric for every action.
6. If content work is recommended, call build-content-brief before drafting copy.
7. Clearly separate observed facts, inferred explanations, and proposed experiments.
8. Never claim a change was published or implemented. Peekchimp only drafts recommendations.
9. End with a measurement plan that compares the same metrics after implementation and accounts for the available data window.

Prefer a few high-confidence actions over a long generic checklist. State data limitations explicitly.
PROMPT);
    }
}
