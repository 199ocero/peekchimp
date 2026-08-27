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
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\FailOnTimeout;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Throwable;

#[FailOnTimeout]
class GenerateInsightRecommendations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 135;

    public int $uniqueFor = 180;

    /** @param array<int, array<string, mixed>> $candidates */
    public function __construct(
        public int $runId,
        public Project $project,
        public array $candidates,
        public string $periodStart,
        public string $periodEnd,
        public bool $force = false,
    ) {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return $this->runId.($this->force ? ':manual' : ':automatic');
    }

    public function handle(AiInsightContextBuilder $contextBuilder, AiProviderRegistry $providers): void
    {
        $run = AiInsightRun::query()->find($this->runId);

        if ($run === null || $run->status !== 'queued') {
            return;
        }

        if (($run->updated_at ?? $run->created_at)->lte(now()->subSeconds((int) config('analytics.ai.stale_after_seconds', 180)))) {
            $run->forceFill([
                'status' => 'failed',
                'error' => 'AI generation waited too long to start. Please try again.',
                'completed_at' => now(),
            ])->save();

            return;
        }

        $claimed = AiInsightRun::query()
            ->whereKey($run->getKey())
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'completed_at' => null,
                'error' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $run->refresh();
        $owner = $this->project->user()->first()?->workspaceOwnerUser();
        $setting = $owner?->workspaceAiSetting()->first();
        $payload = $contextBuilder->build($this->project, $this->candidates, $this->periodStart, $this->periodEnd);
        $contextHash = $contextBuilder->hash($payload);

        $provider = $setting === null ? '' : (string) $setting->provider;
        $hasApiKey = is_string($setting?->api_key) && $setting->api_key !== '';

        if (! config('analytics.ai.enabled', true) || $setting === null || ! $setting->is_enabled || ! $providers->isSupported($provider) || ($providers->requiresApiKey($provider) && ! $hasApiKey)) {
            $run->forceFill([
                'status' => 'skipped',
                'candidate_count' => count($payload['candidates']),
                'error' => 'Configure and enable a supported AI provider before generating recommendations.',
                'completed_at' => now(),
            ])->save();

            return;
        }

        $run->forceFill([
            'provider' => $setting->provider,
            'model' => $setting->model,
            'candidate_count' => count($payload['candidates']),
        ])->save();
        $runtimeProvider = 'peekchimp_'.$this->project->getKey().'_'.substr($contextHash, 0, 12);

        try {
            config(['ai.providers.'.$runtimeProvider => $providers->runtimeConfig($provider, (string) ($setting->api_key ?? ''), $setting->base_url)]);
            $response = AnalyticsInsightAgent::make()->prompt(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                provider: $runtimeProvider,
                model: $setting->model,
                timeout: (int) config('analytics.ai.request_timeout_seconds', 120),
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
        } finally {
            Ai::forgetInstance($runtimeProvider);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception instanceof TimeoutExceededException
            ? 'AI generation timed out before the provider responded. Please try again.'
            : mb_substr($exception?->getMessage() ?? 'AI generation failed unexpectedly.', 0, 2000);

        AiInsightRun::query()
            ->whereKey($this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => $message,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        Log::error('AI insight generation failed.', [
            'run_id' => $this->runId,
            'project_id' => $this->project->getKey(),
            'exception' => $exception,
        ]);
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
