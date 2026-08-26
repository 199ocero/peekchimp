<?php

namespace App\Jobs;

use App\Models\InsightActionAttempt;
use App\Services\Analytics\InsightOutcomeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateInsightOutcomes implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $days = 7)
    {
        $this->onQueue('analytics');
    }

    public function handle(InsightOutcomeService $outcomes): void
    {
        $cutoff = now()->subDays($this->days);

        InsightActionAttempt::query()
            ->where('status', 'completed')
            ->whereNull('outcome')
            ->whereNotNull('acted_at')
            ->where('acted_at', '<=', $cutoff)
            ->with('insight')
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use ($outcomes): void {
                foreach ($attempts as $attempt) {
                    if ($attempt->insight === null || $attempt->acted_at === null) {
                        continue;
                    }

                    $attempt->forceFill([
                        'outcome' => $outcomes->evaluate(
                            $attempt->insight,
                            CarbonImmutable::parse((string) $attempt->acted_at),
                            $this->days,
                        ),
                    ])->save();
                }
            });
    }
}
