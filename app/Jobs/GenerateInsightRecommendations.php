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
use Laravel\Ai\Ai;
use Throwable;

class GenerateInsightRecommendations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    /** @param array<int, array<string, mixed>> $candidates */
    public function __construct(
        public Project $project,
        public array $candidates,
        public string $periodStart,
        public string $periodEnd,
    ) {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return $this->project->getKey().':'.sha1(json_encode($this->candidates).$this->periodStart.$this->periodEnd);
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
        $contextHash = sha1(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '');
        $cooldown = now()->subHours((int) config('analytics.ai.cooldown_hours', 6));

        if (AiInsightRun::query()
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

        if ($run->status === 'completed' && $run->updated_at?->gt($cooldown)) {
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
            $this->applyResponse($structured);
            $run->forceFill([
                'status' => 'completed',
                'input_tokens' => $response->usage->promptTokens ?? null,
                'output_tokens' => $response->usage->completionTokens ?? null,
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
    private function applyResponse(array $structured): void
    {
        $periodStart = CarbonImmutable::parse($this->periodStart)->utc()->toDateTimeString();
        $periodEnd = CarbonImmutable::parse($this->periodEnd)->utc()->toDateTimeString();

        foreach ((array) ($structured['insights'] ?? []) as $insight) {
            if (! is_array($insight) || ! is_string($insight['fingerprint'] ?? null)) {
                continue;
            }

            $record = Insight::query()
                ->where('project_id', $this->project->getKey())
                ->where('fingerprint', $insight['fingerprint'])
                ->where('period_start', $periodStart)
                ->where('period_end', $periodEnd)
                ->first();

            if ($record === null) {
                continue;
            }

            $record->forceFill([
                'explanation' => is_string($insight['explanation'] ?? null) ? mb_substr($insight['explanation'], 0, 2000) : $record->explanation,
                'recommendation' => is_string($insight['recommendation'] ?? null) ? mb_substr($insight['recommendation'], 0, 2000) : $record->recommendation,
                'metadata' => array_merge(
                    is_array($record->getAttribute('metadata')) ? $record->getAttribute('metadata') : [],
                    ['ai_priority' => (int) ($insight['priority'] ?? 0), 'ai_confidence_note' => is_string($insight['confidence_note'] ?? null) ? $insight['confidence_note'] : null],
                ),
            ])->save();
        }
    }
}
