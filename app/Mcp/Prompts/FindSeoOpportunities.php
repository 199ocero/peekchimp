<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Guide a privacy-safe SEO review using aggregate Search Console performance and Google-organic landing-page outcomes.')]
class FindSeoOpportunities extends Prompt
{
    /**
     * Handle the prompt request.
     */
    public function handle(Request $request): Response
    {
        $projectId = $request->get('project_id', 'the selected website');
        $range = $request->get('range', '30d');

        return Response::text("Review SEO opportunities for website project {$projectId} for the {$range} range using get-search-performance and get-organic-search-opportunities. Prioritize pages with meaningful impressions, low CTR, attainable positions, and demonstrated aggregate engagement or conversions. Account for Search Console lag, anonymized queries, and the estimated click-to-visit relationship. Do not infer individual searchers or attribute a query to a person.");
    }

    /**
     * Get the prompt's arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('project_id', 'The Peekchimp website project ID returned by list-websites.', true),
            new Argument('range', 'The range to analyze: today, yesterday, 7d, 30d, month, or custom.', false),
        ];
    }
}
