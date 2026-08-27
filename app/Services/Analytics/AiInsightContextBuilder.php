<?php

namespace App\Services\Analytics;

use App\Models\Project;

class AiInsightContextBuilder
{
    /**
     * Build a bounded, aggregate-only prompt payload.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function build(Project $project, array $candidates, string $periodStart, string $periodEnd): array
    {
        $safeCandidates = array_map(static fn (array $candidate): array => [
            'fingerprint' => substr((string) ($candidate['fingerprint'] ?? ''), 0, 80),
            'category' => substr((string) ($candidate['category'] ?? 'traffic'), 0, 32),
            'metric' => substr((string) ($candidate['metric'] ?? ''), 0, 64),
            'label' => substr((string) ($candidate['label'] ?? ''), 0, 120),
            'current_value' => $candidate['current_value'] ?? null,
            'previous_value' => $candidate['previous_value'] ?? null,
            'percentage_change' => $candidate['percentage_change'] ?? null,
            'confidence' => substr((string) ($candidate['confidence'] ?? 'low'), 0, 16),
            'deterministic_summary' => substr((string) ($candidate['summary'] ?? ''), 0, 500),
        ], array_slice($candidates, 0, (int) config('analytics.ai.max_candidates', 5)));

        $payload = [
            'product' => 'Peekchimp',
            'website' => substr((string) $project->name, 0, 160),
            'period' => ['start' => $periodStart, 'end' => $periodEnd],
            'candidates' => $safeCandidates,
            'rules' => [
                'Use only these facts.',
                'Do not assert causation.',
                'Return exactly one insight for every candidate and preserve its fingerprint exactly.',
                'Do not merely repeat the deterministic summary as the explanation.',
                'Give every candidate a distinct, metric-specific recommendation with a concrete next check.',
                'State what to compare or verify and how that result should guide the next action.',
                'Avoid generic advice such as review sources and pages, monitor the trend, or investigate further.',
                'Recommend aggregate reports and segments only; never ask for a list or export of individual visitors.',
            ],
        ];

        while (strlen(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '') > (int) config('analytics.ai.max_payload_bytes', 12288) && $payload['candidates'] !== []) {
            array_pop($payload['candidates']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $context */
    public function hash(array $context): string
    {
        return sha1(json_encode($context, JSON_UNESCAPED_SLASHES) ?: '');
    }
}
