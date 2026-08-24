<?php

namespace App\Services\Analytics;

use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class VisitorIdentifier
{
    public function make(Project $project, Request $request, CarbonImmutable $at): string
    {
        $ip = (string) $request->ip();
        $userAgent = preg_replace('/\s+/', ' ', strtolower((string) $request->userAgent())) ?? '';
        $day = $at->setTimezone($project->timezone)->toDateString();

        return hash_hmac(
            'sha256',
            implode('|', [$project->getKey(), $day, $ip, substr($userAgent, 0, 512)]),
            (string) config('app.key'),
        );
    }
}
