<?php

namespace App\Jobs;

use App\Ai\Agents\AnalyticsInsightAgent;
use App\Models\AiInsightRun;
use App\Models\Insight;
use App\Models\Project;
use App\Services\Analytics\AiInsightContextBuilder;
use App\Services\Analytics\AiProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Ai;
use Throwable;

class GenerateInsightRecommendations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 300;

    /** @param array<int, array<string, mixed>> $candidates */
    public function __construct(
        public Project $project,
        public array $candidates,
        public string $periodStart,
        public string $periodEnd,
        public bool $force = false,
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        $aggregateChanges = array_map(static fn (array $candidate): array => [
            'fingerprint' => $candidate['fingerprint'] ?? null,
            'current_value' => $candidate['current_value'] ?? null,
            'previous_value' => $candidate['previous_value'] ?? null,
            'percentage_change' => $candidate['percentage_change'] ?? null,
        ], $this->candidates);

        return $this->project->getKey().':'.sha1(json_encode($aggregateChanges).$this->periodStart.$this->periodEnd.($this->force ? ':manual' : ':automatic'));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(AiInsightContextBuilder $contextBuilder, AiProviderRegistry $providers): void
    {
        $owner = $this->project->user()->first()?->workspaceOwnerUser();
        $setting = $owner?->workspaceAiSetting()->first();
        $payload = $contextBuilder->build($this->project, $this->candidates, $this->periodStart, $this->periodEnd);
        $contextHash = $contextBuilder->hash($payload);
        $cooldown = now()->subHours((int) config('analytics.ai.cooldown_hours', 6));

        if (! $this->force && AiInsightRun::query()
            ->where('project_id', $this->project->getKey())
            ->where('status', 'completed')
            ->where('updated_at', '>', $cooldown)
            ->exists()) {
            return;
        }

        $run = AiInsightRun::query()->firstOrCreate(
            ['project_id' => $this->project->getKey(), 'context_hash' => $contextHash],
            ['candidate_count' => count($payload['candidates'])],
        );

        if ($run->status === 'completed') {
            return;
        }

        $provider = $setting === null ? '' : (string) $setting->provider;
        $hasApiKey = is_string($setting?->api_key) && $setting->api_key !== '';

        if (! config('analytics.ai.enabled', true) || $setting === null || ! $setting->is_enabled || ! $providers->isSupported($provider) || ($providers->requiresApiKey($provider) && ! $hasApiKey)) {
            $run->forceFill(['status' => 'skipped', 'candidate_count' => count($payload['candidates']), 'completed_at' => now()])->save();

            return;
        }

        $run->forceFill([
            'provider' => $setting->provider,
            'model' => $setting->model,
            'status' => 'running',
            'candidate_count' => count($payload['candidates']),
            'started_at' => now(),
            'error' => null,
        ])->save();
        $runtimeProvider = 'peekchimp_'.$this->project->getKey().'_'.substr($contextHash, 0, 12);

        try {
            config(['ai.providers.'.$runtimeProvider => $providers->runtimeConfig($provider, (string) ($setting->api_key ?? ''), $setting->base_url)]);
            $response = AnalyticsInsightAgent::make()->prompt(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                provider: $runtimeProvider,
                model: $setting->model,
                timeout: $this->timeout,
            );
            $structured = is_array($response->structured ?? null) ? $response->structured : [];
            $enhancedInsightCount = $this->applyResponse($structured);
            if ($enhancedInsightCount > 0) {
                Cache::forever(
                    'dashboard:ai-insights-version:'.$this->project->getKey(),
                    (string) microtime(true),
                );
            }
            $run->forceFill([
                'status' => $enhancedInsightCount > 0 ? 'completed' : 'skipped',
                'input_tokens' => $response->usage->promptTokens ?? null,
                'output_tokens' => $response->usage->completionTokens ?? null,
                'error' => $enhancedInsightCount > 0
                    ? null
                    : 'AI returned no distinct recommendations for the current analytics changes.',
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ])->save();
        } finally {
            Ai::forgetInstance($runtimeProvider);
        }
    }

    /** @param array<string, mixed> $structured */
    private function applyResponse(array $structured): int
    {
        $periodStart = CarbonImmutable::parse($this->periodStart)->toDateTimeString();
        $periodEnd = CarbonImmutable::parse($this->periodEnd)->toDateTimeString();
        $enhancedInsightCount = 0;
        $recommendationCounts = collect((array) ($structured['insights'] ?? []))
            ->filter(fn (mixed $insight): bool => is_array($insight) && is_string($insight['recommendation'] ?? null))
            ->countBy(fn (array $insight): string => $this->normalizeRecommendation($insight['recommendation']));

        foreach ((array) ($structured['insights'] ?? []) as $insight) {
            if (! is_array($insight) || ! is_string($insight['fingerprint'] ?? null)) {
                continue;
            }

            $candidate = collect($this->candidates)->first(
                fn (array $candidate): bool => ($candidate['fingerprint'] ?? null) === $insight['fingerprint'],
            );
            $recommendation = is_string($insight['recommendation'] ?? null)
                ? trim($insight['recommendation'])
                : '';
            $normalizedRecommendation = $this->normalizeRecommendation($recommendation);
            $normalizedFallback = $this->normalizeRecommendation((string) ($candidate['recommendation'] ?? ''));

            if (
                mb_strlen($recommendation) < 30
                || $normalizedRecommendation === $normalizedFallback
                || $recommendationCounts->get($normalizedRecommendation, 0) > 1
                || $this->requestsIndividualVisitorData($recommendation)
            ) {
                continue;
            }

            $recordQuery = Insight::query()
                ->where('project_id', $this->project->getKey())
                ->where('fingerprint', $insight['fingerprint']);

            if (is_int($candidate['id'] ?? null)) {
                $recordQuery->whereKey($candidate['id']);
            } else {
                $recordQuery
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd);
            }

            $record = $recordQuery->first();

            if ($record === null) {
                continue;
            }

            $record->forceFill([
                'explanation' => is_string($insight['explanation'] ?? null) ? mb_substr($insight['explanation'], 0, 2000) : $record->explanation,
                'recommendation' => mb_substr($recommendation, 0, 2000),
                'metadata' => array_merge(
                    is_array($record->getAttribute('metadata')) ? $record->getAttribute('metadata') : [],
                    [
                        'ai_enhanced' => true,
                        'ai_generated_at' => now()->toIso8601String(),
                        'ai_priority' => (int) ($insight['priority'] ?? 0),
                        'ai_confidence_note' => is_string($insight['confidence_note'] ?? null) ? $insight['confidence_note'] : null,
                    ],
                ),
            ])->save();
            $enhancedInsightCount++;
        }

        return $enhancedInsightCount;
    }

    private function normalizeRecommendation(string $recommendation): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $recommendation) ?? ''));
    }

    private function requestsIndividualVisitorData(string $recommendation): bool
    {
        return preg_match('/\b(?:export|identify|inspect|list|pull)\b.{0,60}\b(?:individual\s+)?visitors?\b/i', $recommendation) === 1;
    }
}
