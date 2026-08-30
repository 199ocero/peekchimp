<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('peekchimp://guide/analytics-methodology')]
#[MimeType('text/markdown')]
#[Description('Definitions, comparison rules, privacy boundaries, and caveats for interpreting Peekchimp analytics and Search Console data.')]
class AnalyticsMethodologyResource extends Resource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        return Response::text(<<<'MARKDOWN'
            # Peekchimp analytics methodology

            ## Analytics
            - Visits are analytics sessions. Visitors are distinct anonymous visitor IDs.
            - Countries, sources, devices, browsers, campaigns, entry pages, and exit pages are aggregate session breakdowns.
            - Time ranges use the website timezone; the database comparison windows are converted to UTC.
            - Comparison changes use the immediately preceding window of the same length. A missing baseline is reported as unavailable, not as zero growth.
            - AI referrals and AI traffic are classified from session referrers and UTM sources. They never expose visitor identifiers.

            ## Search Console
            - Search Console metrics are reported for the exact verified property and may lag by two or three days.
            - Google can omit anonymized queries, truncate large result sets, and report a different timezone or rounding than the UI.
            - Organic landing-page analysis joins Search Console paths to aggregate Google-organic sessions by normalized landing path and date. Paid Google sessions are excluded.
            - Tracked visit rate is an estimate of visits divided by Search Console clicks, not a user-level attribution claim.
            - Query and visitor identifiers are not linked or returned as personal attribution.

            ## Public page snapshots
            - Website scans fetch only HTTP or HTTPS pages on the project's exact verified domains and reject private or reserved network addresses.
            - Redirects, response time, response size, status, content type, titles, descriptions, canonicals, robots directives, headings, links, CTA candidates, structured-data validity, and bounded main-page text are recorded.
            - Scripts, forms, inputs, styles, and other non-content elements are removed before text is stored. Response and extracted-text sizes are bounded.
            - Snapshot freshness and crawl coverage are returned with recommendations. A missing or stale snapshot limits confidence and should trigger a new scan before final copy is approved.
            - Content recommendations and experiments are drafts. Peekchimp never publishes a website change through MCP.

            ## Interpretation
            Treat small changes and partial current days cautiously. Prefer sustained patterns over one-day spikes, and use the returned range, baseline, status, data-through, freshness, evidence references, and crawl coverage fields when explaining a result. Clearly separate measured facts, inferred explanations, and proposed experiments.
            MARKDOWN);
    }
}
