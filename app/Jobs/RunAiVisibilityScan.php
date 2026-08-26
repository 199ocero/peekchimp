<?php

namespace App\Jobs;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Services\Analytics\AiVisibilityScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RunAiVisibilityScan implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public Project $project, public AiVisibilityScan $scan)
    {
        $this->onQueue('analytics');
    }

    public function handle(AiVisibilityScanner $scanner): void
    {
        $scanner->scan($this->project, $this->scan);
    }
}
