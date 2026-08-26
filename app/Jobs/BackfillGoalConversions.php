<?php

namespace App\Jobs;

use App\Models\Goal;
use App\Services\Analytics\GoalConversionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillGoalConversions implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public Goal $goal,
        public ?string $from = null,
        public ?string $to = null,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(GoalConversionService $conversions): void
    {
        $from = $this->from === null ? null : CarbonImmutable::parse($this->from);
        $to = $this->to === null ? null : CarbonImmutable::parse($this->to);
        $conversions->backfill($this->goal, $from, $to);
    }
}
