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
            'deterministic_recommendation' => substr((string) ($candidate['recommendation'] ?? ''), 0, 500),
        ], array_slice($candidates, 0, (int) config('analytics.ai.max_candidates', 5)));

        $payload = [
            'product' => 'Peekchimp',
            'website' => substr((string) $project->name, 0, 160),
            'period' => ['start' => $periodStart, 'end' => $periodEnd],
            'candidates' => $safeCandidates,
            'rules' => [
                'Use only these facts.',
                'Do not assert causation.',
                'Recommendations must be safe investigations grounded in the facts.',
            ],
        ];

        while (strlen(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '') > (int) config('analytics.ai.max_payload_bytes', 12288) && $payload['candidates'] !== []) {
            array_pop($payload['candidates']);
        }

        return $payload;
    }
}
