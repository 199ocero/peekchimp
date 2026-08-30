<?php

namespace App\Jobs;

use App\Models\AiVisibilityScan;
use App\Models\Project;
use App\Services\Analytics\AiVisibilityScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunAiVisibilityScan implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public Project $project, public AiVisibilityScan $scan)
    {
        $this->onQueue('crawl');
    }

    public function handle(AiVisibilityScanner $scanner): void
    {
        $scanner->scan($this->project, $this->scan);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('website-crawl-'.$this->project->getKey()))
            ->dontRelease()
            ->expireAfter(180)];
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['crawl', 'project:'.$this->project->getKey(), 'scan:'.$this->scan->getKey()];
    }
}
