<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Guide an analysis of aggregate traffic, engagement, conversions, and AI referral trends for a Peekchimp website.')]
class AnalyzeWebsitePerformance extends Prompt
{
    /**
     * Handle the prompt request.
     */
    public function handle(Request $request): Response
    {
        $projectId = $request->get('project_id', 'the selected website');
        $range = $request->get('range', '30d');

        return Response::text("Analyze website project {$projectId} for the {$range} range using get-analytics-overview. Explain the strongest changes versus the preceding period, connect traffic sources to engagement and conversions, and distinguish measured facts from hypotheses. Use only aggregate data and mention missing comparison or Search Console data when relevant.");
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
