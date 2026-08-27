<?php

namespace App\Services\Analytics;

use App\Jobs\GenerateInsightRecommendations;
use App\Models\AiInsightRun;
use App\Models\Project;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AiInsightGenerationCoordinator
{
    public function __construct(
        private readonly AiInsightContextBuilder $contextBuilder,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{run: AiInsightRun|null, dispatched: bool, reason: string}
     */
    public function request(
        Project $project,
        array $candidates,
        string $periodStart,
        string $periodEnd,
        bool $force = false,
    ): array {
        $context = $this->contextBuilder->build($project, $candidates, $periodStart, $periodEnd);
        $contextHash = $this->contextBuilder->hash($context);

        return Cache::lock($this->lockKey($project, $contextHash), 10)->block(
            5,
            fn (): array => $this->requestWhileLocked(
                $project,
                $candidates,
                $periodStart,
                $periodEnd,
                $contextHash,
                $force,
            ),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{id: int, status: string, error: string|null, updatedAt: string}|null
     */
    public function status(
        Project $project,
        array $candidates,
    ): ?array {
        $periodStart = data_get($candidates, '0.period_start');
        $periodEnd = data_get($candidates, '0.period_end');

        if ($candidates === [] || ! is_string($periodStart) || ! is_string($periodEnd)) {
            return null;
        }

        $context = $this->contextBuilder->build($project, $candidates, $periodStart, $periodEnd);
        $run = AiInsightRun::query()
            ->where('project_id', $project->getKey())
            ->where('context_hash', $this->contextBuilder->hash($context))
            ->first();

        if ($run === null) {
            return null;
        }

        $isStale = $this->isStale($run);

        return [
            'id' => (int) $run->getKey(),
            'status' => $isStale ? 'failed' : (string) $run->status,
            'error' => $isStale
                ? 'AI generation stopped before it finished. Please try again.'
                : (is_string($run->error) ? $run->error : null),
            'updatedAt' => ($run->updated_at ?? $run->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{run: AiInsightRun|null, dispatched: bool, reason: string}
     */
    private function requestWhileLocked(
        Project $project,
        array $candidates,
        string $periodStart,
        string $periodEnd,
        string $contextHash,
        bool $force,
    ): array {
        $run = AiInsightRun::query()
            ->where('project_id', $project->getKey())
            ->where('context_hash', $contextHash)
            ->first();

        if ($run?->status === 'completed') {
            return ['run' => $run, 'dispatched' => false, 'reason' => 'completed'];
        }

        if ($run !== null && in_array($run->status, ['queued', 'running'], true) && ! $this->isStale($run)) {
            return ['run' => $run, 'dispatched' => false, 'reason' => 'in_progress'];
        }

        if (! $force && $this->isWithinAutomaticCooldown($project)) {
            return ['run' => $run, 'dispatched' => false, 'reason' => 'cooldown'];
        }

        $run ??= new AiInsightRun([
            'project_id' => $project->getKey(),
            'context_hash' => $contextHash,
        ]);
        $run->forceFill([
            'status' => 'queued',
            'candidate_count' => count($candidates),
            'input_tokens' => null,
            'output_tokens' => null,
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ])->save();

        try {
            Bus::dispatch(new GenerateInsightRecommendations(
                (int) $run->getKey(),
                $project,
                $candidates,
                $periodStart,
                $periodEnd,
                $force,
            ));
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error' => 'Peekchimp could not queue AI generation. Please try again.',
                'completed_at' => now(),
            ])->save();
            report($exception);

            return ['run' => $run, 'dispatched' => false, 'reason' => 'failed'];
        }

        return ['run' => $run, 'dispatched' => true, 'reason' => 'queued'];
    }

    private function isWithinAutomaticCooldown(Project $project): bool
    {
        return AiInsightRun::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'completed')
            ->where('updated_at', '>', now()->subHours((int) config('analytics.ai.cooldown_hours', 6)))
            ->exists();
    }

    private function isStale(AiInsightRun $run): bool
    {
        return in_array($run->status, ['queued', 'running'], true)
            && ($run->updated_at ?? $run->created_at)->lte(now()->subSeconds((int) config('analytics.ai.stale_after_seconds', 180)));
    }

    private function lockKey(Project $project, string $contextHash): string
    {
        return 'dashboard:ai-insight-generation:'.$project->getKey().':'.$contextHash;
    }
}
